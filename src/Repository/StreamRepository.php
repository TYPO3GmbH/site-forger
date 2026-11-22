<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Document\StreamEvent;
use App\Elastic\Indices;
use App\Elastic\SearchResult;
use App\Enum\StreamSourceEnum;
use Elastica\Document;
use Elastica\Query;

/**
 * The events of the activity stream in the stream index, see App\Stream\StreamWriter for what writes them.
 *
 * Not final: the tests stub it, they have no Elasticsearch.
 */
readonly class StreamRepository
{
    private const int BULK_SIZE = 1000;

    public function __construct(
        private Indices $indices,
    ) {
    }

    /**
     * Writes the events of the issues or changes and deletes the ones they do not hold any more, like a note that
     * Forge made private. The events overwrite the earlier ones by their id first, so the stream never lacks them.
     *
     * @param list<int>         $numbers the issues or changes, also those without events
     * @param list<StreamEvent> $events
     */
    public function replace(StreamSourceEnum $source, array $numbers, array $events): void
    {
        if ([] === $numbers) {
            return;
        }
        foreach (array_chunk($events, self::BULK_SIZE) as $chunk) {
            $this->indices->stream()->addDocuments(array_map(
                static fn (StreamEvent $event): Document => new Document($event->id, $event->toDocument()),
                $chunk,
            ));
        }
        $kept = array_map(static fn (StreamEvent $event): string => $event->id, $events);
        $this->delete(self::ofItems($source, $numbers, $kept));
    }

    /**
     * Deletes all events of the issues or changes, for those the index removed.
     *
     * @param list<int|string> $numbers
     */
    public function remove(StreamSourceEnum $source, array $numbers): void
    {
        if ([] !== $numbers) {
            $this->delete(self::ofItems($source, array_map(intval(...), $numbers), []));
        }
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return SearchResult<StreamEvent>
     */
    public function search(array $request): SearchResult
    {
        return SearchResult::of(
            $this->indices->stream()->createSearch($request)->search(),
            StreamEvent::fromDocument(...),
        );
    }

    /**
     * @param list<int>    $numbers
     * @param list<string> $kept    the ids of the events to keep
     *
     * @return array<string, mixed>
     */
    private static function ofItems(StreamSourceEnum $source, array $numbers, array $kept): array
    {
        $query = ['bool' => ['filter' => [
            ['term' => ['source' => $source->value]],
            ['terms' => ['number' => $numbers]],
        ]]];
        if ([] !== $kept) {
            $query['bool']['must_not'] = [['ids' => ['values' => $kept]]];
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function delete(array $query): void
    {
        $this->indices->stream()->deleteByQuery(
            new Query(['query' => $query]),
            ['refresh' => 'true', 'conflicts' => 'proceed'],
        );
    }
}
