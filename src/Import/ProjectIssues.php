<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Elastic\Indices;
use App\Enum\StreamSourceEnum;
use App\Repository\StreamRepository;
use Elastica\Query;
use Elastica\Query\MatchAll;
use Elastica\Result;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Forger counts and shows the issues of the Forge project itself (redmine_project_id).
 *
 * Asked for a project, Forge also lists the issues of its subprojects. They are not imported, and removed
 * from the index when they are found there, e.g. after an issue was moved to another project.
 */
final readonly class ProjectIssues
{
    public function __construct(
        private Indices $indices,
        private StreamRepository $stream,
        #[Autowire('%redmine_project_id%')]
        private int $projectId,
    ) {
    }

    /**
     * @param array<string, mixed> $issue as Forge lists it
     */
    public function belongsToProject(array $issue): bool
    {
        return (int) ($issue['project']['id'] ?? 0) === $this->projectId && true !== ($issue['is_private'] ?? false);
    }

    /**
     * The issue without its private notes and without the files added with them. The public sees neither, the token
     * of Forger does. A private note names its files as changes of the field "attachment", by their id.
     *
     * @param array<string, mixed> $issue
     *
     * @return array<string, mixed>
     */
    public function withoutPrivateNotes(array $issue): array
    {
        if (!isset($issue['journals']) || !is_array($issue['journals'])) {
            return $issue;
        }
        $isPrivate = static fn (array $journal): bool => true === ($journal['private_notes'] ?? false);
        $privateFiles = [];
        foreach (array_filter($issue['journals'], $isPrivate) as $journal) {
            foreach ((array) ($journal['details'] ?? []) as $detail) {
                if ('attachment' === ($detail['property'] ?? null)) {
                    $privateFiles[(int) ($detail['name'] ?? 0)] = true;
                }
            }
        }
        $issue['journals'] = array_values(array_filter(
            $issue['journals'],
            static fn (array $journal): bool => !$isPrivate($journal),
        ));
        if ([] !== $privateFiles && is_array($issue['attachments'] ?? null)) {
            $issue['attachments'] = array_values(array_filter(
                $issue['attachments'],
                static fn (mixed $file): bool => !isset($privateFiles[(int) ($file['id'] ?? 0)]),
            ));
        }

        return $issue;
    }

    /**
     * Deletes the issues of other projects and the private ones from the index, and their events from the activity
     * stream. They are looked up first: the stream finds their events by number.
     *
     * @return int the number of deleted issues
     */
    public function removeOtherProjects(): int
    {
        $query = ['bool' => [
            'should' => [
                ['bool' => ['must_not' => [['term' => ['project.id' => $this->projectId]]]]],
                ['term' => ['is_private' => true]],
            ],
            'minimum_should_match' => 1,
        ]];
        $search = $this->indices->issues()->createSearch(
            new Query(['query' => $query, '_source' => false, 'size' => 1000]),
        );
        $ids = [];
        foreach ($search->scroll('2m') as $resultSet) {
            foreach ($resultSet as $result) {
                $ids[] = (int) $result->getId();
            }
        }

        return $this->remove($ids);
    }

    /**
     * The ids of all issues in the index.
     *
     * @return \Generator<string>
     */
    public function indexedIds(): \Generator
    {
        $search = $this->indices->issues()->createSearch(new Query(new MatchAll())->setSource(false)->setSize(1000));
        foreach ($search->scroll('2m') as $resultSet) {
            foreach ($resultSet as $result) {
                yield (string) $result->getId();
            }
        }
    }

    /**
     * How many issues the index has, with a number in the range if one is given, see ForgeImport::reconcile().
     */
    public function count(?int $from = null, ?int $to = null): int
    {
        return $this->indices->issues()->count(new Query(['query' => self::range($from, $to)]));
    }

    /**
     * The highest number of an issue in the index, 0 if it has none.
     */
    public function highestId(): int
    {
        $result = $this->indices->issues()->search(new Query(['size' => 0, 'aggs' => ['highest' => ['max' => ['field' => 'id']]]]));

        return (int) ($result->getAggregation('highest')['value'] ?? 0);
    }

    /**
     * The numbers of the issues in the index in the range, at most 1000: the ranges compared with Forge hold a page.
     *
     * @return list<int>
     */
    public function idsBetween(int $from, int $to): array
    {
        $results = $this->indices->issues()->search(new Query(['query' => self::range($from, $to), '_source' => false, 'size' => 1000]));

        return array_values(array_map(
            static fn (Result $result): int => (int) $result->getId(),
            $results->getResults(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function range(?int $from, ?int $to): array
    {
        return null === $from || null === $to ? ['match_all' => new \stdClass()] : ['range' => ['id' => ['gte' => $from, 'lte' => $to]]];
    }

    /**
     * Removes issues from the index, and their events from the activity stream.
     *
     * @param list<int|string> $ids
     *
     * @return int the number of deleted issues, ids not in the index are ignored
     */
    public function remove(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }
        $response = $this->indices->issues()->deleteByQuery(
            new Query(['query' => ['ids' => ['values' => array_map('strval', $ids)]]]),
            ['refresh' => 'true', 'conflicts' => 'proceed'],
        )->getData();
        $this->stream->remove(StreamSourceEnum::FORGE, $ids);

        return (int) ($response['deleted'] ?? 0);
    }
}
