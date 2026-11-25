<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import\Backfill;

use App\CommandRun\CommandRunRecorder;
use App\CommandRun\JobStopped;
use App\Elastic\Indices;
use App\Elastic\SyncAnswer;
use App\Import\ImportRun;
use App\Import\ImportSchema;
use Elastica\Bulk;
use Elastica\Bulk\Action;
use Elastica\Exception\Bulk\ResponseException;
use Elastica\Index;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Lifts the stored documents to a newer version without a request to Forge or Gerrit, step by step, see
 * BackfillStep: the fields of a newer ImportSchema, and the events of the activity stream. It writes the fields of
 * a step only, so a run takes all documents at once, the ones changed last first.
 *
 * It takes no lock of the imports and runs beside them. Each update names the state of the document it read
 * ("if_seq_no"): when an import wrote the document in between, Elasticsearch refuses the update, and the document
 * of the import stays. An import writes a document before its events, so a step that writes outside the document
 * always learns of it and works again, see write().
 */
final readonly class SchemaBackfill
{
    public const string STEP = 'Lift the stored documents';

    private const int BATCH = 250;

    /**
     * How often a step that writes outside works again on a document an import keeps writing.
     */
    private const int ROUNDS = 3;

    private const int CONFLICT = 409;

    /**
     * @var list<BackfillStep> a step of ImportSchema after the one it builds on
     */
    private array $steps;

    /**
     * @param iterable<BackfillStep> $steps
     */
    public function __construct(
        #[AutowireIterator(BackfillStep::TAG)]
        iterable $steps,
        private Indices $indices,
        private CommandRunRecorder $recorder,
    ) {
        $steps = [...$steps];
        usort($steps, static fn (BackfillStep $a, BackfillStep $b): int => self::order($a) <=> self::order($b));
        $this->steps = $steps;
    }

    /**
     * @return list<BackfillStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Whether the steps lead from the version of ImportSchema to the current one of the index.
     *
     * @param 'issue'|'review' $index
     */
    public function reaches(string $index, int $version): bool
    {
        $from = [];
        foreach ($this->steps as $step) {
            if ($step instanceof SchemaStep && $step->index() === $index) {
                $from[] = $step->from();
            }
        }
        for ($next = $version; $next < ImportSchema::current($index); ++$next) {
            if (!in_array($next, $from, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return int how many documents it lifted
     *
     * @throws JobStopped
     */
    public function run(ImportRun $run): int
    {
        $lifted = 0;
        foreach ($this->steps as $step) {
            $lifted += $this->take($run, $step);
        }

        return $lifted;
    }

    /**
     * The steps of ImportSchema first, in the order of their versions.
     *
     * @return array{string, int, string}
     */
    private static function order(BackfillStep $step): array
    {
        return [$step->index(), $step instanceof SchemaStep ? $step->from() : \PHP_INT_MAX, $step->name()];
    }

    /**
     * @throws JobStopped
     */
    private function take(ImportRun $run, BackfillStep $step): int
    {
        $index = $this->indices->get($step->index());
        $total = $index->count(['query' => $step->outdated()]);
        if (0 === $total) {
            return 0;
        }
        $run->writeln(sprintf('%s: %d documents', $step->name(), $total));
        $this->recorder->progress(self::STEP, 0, $total);
        $lifted = 0;
        $seen = 0;
        $after = null;
        do {
            ['documents' => $documents, 'after' => $after] = $this->batch($index, $step, $after);
            if ([] === $documents) {
                break;
            }
            $written = $this->write($index, $step, $documents);
            $this->recorder->document($written);
            $lifted += $written;
            $seen += count($documents);
            $this->recorder->progress(self::STEP, $seen, $total);
            $run->refreshLock();
        } while (self::BATCH === count($documents));
        if ($lifted < $seen) {
            $this->recorder->note(sprintf(
                '%s: an import wrote %s documents at the same time.',
                $step->name(),
                number_format($seen - $lifted),
            ));
        }
        // The next step and the next count must see the new version
        $index->refresh();

        return $lifted;
    }

    /**
     * The next documents of the step, the ones changed last first. A search after the last one needs no refresh.
     *
     * @param array<mixed>|null $after the sort values of the last document
     *
     * @return array{documents: list<array<string, mixed>>, after: array<mixed>|null}
     */
    private function batch(Index $index, BackfillStep $step, ?array $after): array
    {
        $search = [
            'query' => $step->outdated(),
            'sort' => [['updated_on' => 'desc'], ['id' => 'asc']],
            '_source' => $step->fields(),
            'size' => self::BATCH,
            'seq_no_primary_term' => true,
        ];
        if (null !== $after) {
            $search['search_after'] = $after;
        }
        $documents = [];
        foreach ($index->search($search)->getResults() as $result) {
            $documents[] = self::document($result->getHit());
            $after = $result->getSort();
        }

        return ['documents' => $documents, 'after' => $after];
    }

    /**
     * Lets the step work on the documents and writes its fields. A step that writes outside works again on the
     * documents an import wrote in the meantime: what it wrote from the old document would stay otherwise.
     *
     * @param non-empty-list<array<string, mixed>> $documents each with "_seq_no" and "_primary_term"
     *
     * @return int how many documents Elasticsearch took
     */
    private function write(Index $index, BackfillStep $step, array $documents): int
    {
        $written = 0;
        for ($round = 0; [] !== $documents && $round < self::ROUNDS; ++$round) {
            $changed = $this->update($index, $documents, $step->lift($documents));
            $written += count($documents) - count($changed);
            $documents = $step->writesOutside() && [] !== $changed
                ? $this->byIds($index, $step->fields(), $changed)
                : [];
        }

        return $written;
    }

    /**
     * @param list<array<string, mixed>>       $documents
     * @param array<int, array<string, mixed>> $fields    by the id of the document
     *
     * @return list<string> the documents an import wrote since they were read
     */
    private function update(Index $index, array $documents, array $fields): array
    {
        $bulk = new Bulk($index->getClient());
        $bulk->setIndex($index);
        foreach ($documents as $document) {
            $unchanged = ['if_seq_no' => $document['_seq_no'], 'if_primary_term' => $document['_primary_term']];
            $bulk->addAction(new Action(
                Action::OP_TYPE_UPDATE,
                ['_id' => (string) $document['id'], ...$unchanged],
                ['doc' => $fields[(int) $document['id']] ?? []],
            ));
        }
        try {
            $bulk->send();
        } catch (ResponseException $exception) {
            return self::changed($exception);
        }

        return [];
    }

    /**
     * The updates Elasticsearch refused because the document changed since it was read. Any other failure stops
     * the run.
     *
     * @return list<string>
     */
    private static function changed(ResponseException $exception): array
    {
        $changed = [];
        foreach ($exception->getResponseSet()->getBulkResponses() as $response) {
            if (!$response->hasError()) {
                continue;
            }
            if (self::CONFLICT !== (int) ($response->getData()['status'] ?? 0)) {
                throw $exception;
            }
            $changed[] = (string) $response->getData()['_id'];
        }

        return $changed;
    }

    /**
     * The documents as they are now, read from the primary: a search may still show the ones from before.
     *
     * @param list<non-empty-string> $fields
     * @param list<string>           $ids
     *
     * @return list<array<string, mixed>>
     */
    private function byIds(Index $index, array $fields, array $ids): array
    {
        $response = SyncAnswer::of($index->getClient()->mget([
            'index' => $index->getName(),
            'body' => ['ids' => $ids],
            '_source' => implode(',', $fields),
        ]))->asArray();

        return array_map(
            self::document(...),
            array_values(array_filter(
                (array) ($response['docs'] ?? []),
                static fn (array $hit): bool => (bool) ($hit['found'] ?? false),
            )),
        );
    }

    /**
     * @param array<string, mixed> $hit a hit of a search or of "_mget"
     *
     * @return array<string, mixed>
     */
    private static function document(array $hit): array
    {
        return [
            ...(array) ($hit['_source'] ?? []),
            'id' => (int) $hit['_id'],
            '_seq_no' => $hit['_seq_no'],
            '_primary_term' => $hit['_primary_term'],
        ];
    }
}
