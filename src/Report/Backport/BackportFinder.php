<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report\Backport;

use App\Elastic\Indices;
use App\Enum\ReviewStatusEnum;
use Elastica\Result;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds the changes merged into main within a year that target an older release and still wait for a backport.
 *
 * A change and its backports share the Change-Id. A release of the footer "Releases:" is "missing" without a
 * change on its branch, else it has that change. A change whose backports are all there and reviewed is left out.
 */
final readonly class BackportFinder
{
    public const string MISSING = 'missing';
    private const string NOT_TARGETED = 'notTargeted';
    private const string TO_BE_DECIDED = 'tbd';

    /**
     * The changes the search looks at, the ones updated last.
     */
    private const int CANDIDATES = 5000;

    /**
     * The backports of this many changes are loaded with one request.
     */
    private const int CHANGE_IDS_PER_REQUEST = 100;

    /**
     * A change has at most this many changes with its Change-Id, one per branch and the ones abandoned.
     */
    private const int CHANGES_PER_CHANGE_ID = 50;

    /**
     * @param list<string> $releases the releases to find the backports of, see the parameter "backport_releases"
     */
    public function __construct(
        private Indices $indices,
        #[Autowire('%backport_releases%')]
        private array $releases,
    ) {
    }

    /**
     * @param list<string> $excluded the Change-Ids of the changes excluded from the view
     *
     * @return array<string, array{mainReviewId: int, releases: array<string, string|array<string, mixed>>}> by Change-Id
     */
    public function find(array $excluded): array
    {
        $candidates = $this->candidates($excluded);
        $related = $this->changesByChangeId(array_values(array_map(
            static fn (Result $result): string => (string) $result->getSource()['change_id'],
            $candidates,
        )));
        $backports = [];
        foreach ($candidates as $candidate) {
            $source = $candidate->getSource();
            $changeId = (string) $source['change_id'];
            $releases = self::untargeted();
            foreach ($source['releases'] ?? [] as $release) {
                $release = str_replace('master', 'main', (string) $release);
                $key = 't3_' . str_replace('.', '', strtolower($release));
                $releases[$key] = self::changeOnBranch($release, $related[$changeId] ?? []);
            }
            if (!self::isComplete($releases)) {
                $backports[$changeId] = ['mainReviewId' => (int) $source['id'], 'releases' => $releases];
            }
        }

        return $backports;
    }

    /**
     * @return array<string, string> every release by its key, main to be decided, the others not targeted
     */
    private static function untargeted(): array
    {
        $releases = [];
        foreach (Backports::versions() as $key => $version) {
            $releases[$key] = 'main' === $version ? self::TO_BE_DECIDED : self::NOT_TARGETED;
        }

        return $releases;
    }

    /**
     * Whether no backport is missing and none waits for its review.
     *
     * @param array<string, string|array<string, mixed>> $releases
     */
    private static function isComplete(array $releases): bool
    {
        foreach ($releases as $change) {
            if (self::MISSING === $change) {
                return false;
            }
            if (is_array($change) && ReviewStatusEnum::NEW->value === $change['reviewstatus']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $changes the changes with the Change-Id
     *
     * @return string|array<string, mixed> the change on the branch of the release, or "missing"
     */
    private static function changeOnBranch(string $release, array $changes): string|array
    {
        $branch = Backports::VERSION_BRANCHES[$release] ?? null;
        foreach ($changes as $change) {
            if ($branch === $change['branch']) {
                unset($change['mergeable']);

                return $change;
            }
        }

        return self::MISSING;
    }

    /**
     * The merged changes of main that target one of the releases, without the security fixes, the ones in
     * progress and the excluded ones, the least recently updated first.
     *
     * @param list<string> $excluded
     *
     * @return Result[]
     */
    private function candidates(array $excluded): array
    {
        $request = [
            'query' => [
                'bool' => [
                    'must' => [
                        ['terms' => ['reviewstatus' => [ReviewStatusEnum::MERGED->value]]],
                        ['terms' => ['branch' => ['main']]],
                    ],
                    'should' => [
                        ['terms' => ['releases' => $this->releases]],
                    ],
                    'must_not' => [
                        ['terms' => ['reviewtags' => ['SECURITY']]],
                        ['term' => ['work_in_progress' => true]],
                        ['terms' => ['change_id' => $excluded]],
                        ['range' => ['updated_on' => ['lte' => 'now-1y']]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
            'sort' => ['updated_on'],
            'size' => self::CANDIDATES,
        ];

        return $this->indices->reviews()->createSearch($request)->search()->getResults();
    }

    /**
     * @param list<string> $changeIds
     *
     * @return array<string, list<array<string, mixed>>> the changes with each Change-Id
     */
    private function changesByChangeId(array $changeIds): array
    {
        $changes = [];
        foreach (array_chunk(array_values(array_unique($changeIds)), self::CHANGE_IDS_PER_REQUEST) as $chunk) {
            $results = $this->indices->reviews()->createSearch([
                'query' => ['bool' => ['must' => [['terms' => ['change_id' => $chunk]]]]],
                'size' => count($chunk) * self::CHANGES_PER_CHANGE_ID,
            ])->search()->getResults();
            foreach ($results as $result) {
                $source = $result->getSource();
                $changes[(string) $source['change_id']][] = $source;
            }
        }

        return $changes;
    }
}
