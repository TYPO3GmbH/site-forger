<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\ReviewStatusEnum;
use App\Search\ReviewLists;
use App\Search\ReviewSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewListsTest extends TestCase
{
    private const array CLOSED = ['terms' => ['reviewstatus' => ['MERGED', 'ABANDONED']]];
    private const array WORK_IN_PROGRESS = ['term' => ['work_in_progress' => true]];

    protected function setUp(): void
    {
        self::assertSame(self::CLOSED['terms']['reviewstatus'], ReviewStatusEnum::closedValues());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openLists(): iterable
    {
        foreach ([
            ReviewLists::READY, ReviewLists::REVIEWED_AND_VERIFIED, ReviewLists::NEEDS_VERIFICATION,
            ReviewLists::NEEDS_REVIEW, ReviewLists::NEEDS_REBASE, ReviewLists::NEGATIVE_VOTES,
        ] as $list) {
            yield $list => [$list];
        }
    }

    #[DataProvider('openLists')]
    public function testListsLeaveOutClosedChangesAndWorkInProgress(string $list): void
    {
        self::assertSame([self::CLOSED, self::WORK_IN_PROGRESS], self::listQuery($list)['bool']['must_not']);
    }

    public function testReadyToMergeHasPositiveAndNoNegativeVotes(): void
    {
        self::assertSame(
            ['bool' => [
                'must' => [
                    ['range' => ['positive_reviews' => ['gte' => 1]]],
                    ['range' => ['positive_verifications' => ['gte' => 1]]],
                    ['term' => ['mergeable' => 'yes']],
                ],
                'must_not' => [
                    self::CLOSED,
                    self::WORK_IN_PROGRESS,
                    ['range' => ['negative_reviews' => ['gte' => 1]]],
                    ['range' => ['negative_verifications' => ['gte' => 1]]],
                ],
            ]],
            ReviewLists::query(ReviewLists::READY_TO_MERGE)
        );
    }

    public function testNegativeVotesNeedOneOfThem(): void
    {
        self::assertSame(
            [['range' => ['negative_reviews' => ['gte' => 1]]], ['range' => ['negative_verifications' => ['gte' => 1]]]],
            self::listQuery(ReviewLists::NEGATIVE_VOTES)['bool']['should']
        );
    }

    public function testWorkInProgressShowsOpenOnesOnly(): void
    {
        self::assertSame(
            ['bool' => ['must' => [self::WORK_IN_PROGRESS], 'must_not' => [self::CLOSED]]],
            ReviewLists::query(ReviewLists::WORK_IN_PROGRESS)
        );
    }

    public function testTheFilterFileFindsAFileOrTheFilesOfAFolder(): void
    {
        $query = static fn (string $path): array => ['bool' => [
            'should' => [['term' => ['files' => $path]], ['prefix' => ['files' => $path . '/']]],
            'minimum_should_match' => 1,
        ]];

        self::assertSame($query('typo3/sysext/core/Classes/Foo.php'), ReviewLists::fileQuery('typo3/sysext/core/Classes/Foo.php'));
        // A "/" around the path counts for nothing, a folder finds its files
        self::assertSame($query('typo3/sysext/core'), ReviewLists::fileQuery(' /typo3/sysext/core/ '));
        // As stored: a path with "&" is not escaped
        self::assertSame($query('Build/a&b.json'), ReviewLists::fileQuery('Build/a&b.json'));
        self::assertNull(ReviewLists::fileQuery(' / '));
    }

    public function testUnknownListHasNoQuery(): void
    {
        self::assertNull(ReviewLists::query('issues_stale'));
        self::assertNull(ReviewLists::query(''));
    }

    public function testSearchWordsNarrowDownAList(): void
    {
        $list = self::listQuery(ReviewLists::NEGATIVE_VOTES);
        $query = self::listQuery(ReviewLists::NEGATIVE_VOTES, false, 'cache');

        // Kept apart, so the "should" of the negative votes stays required
        self::assertSame($list, $query['bool']['must'][0]);
        self::assertSame(ReviewSearch::query('cache'), $query['bool']['must'][1]);
        self::assertSame($list, ReviewLists::query(ReviewLists::NEGATIVE_VOTES, false, ' '));
    }

    public function testTheSearchFindsWorkInProgressAndClosedChangesOnRequest(): void
    {
        self::assertSame(['bool' => ['must_not' => [self::CLOSED]]], ReviewLists::query(ReviewLists::SEARCH));
        self::assertEquals(['match_all' => new \stdClass()], ReviewLists::query(ReviewLists::SEARCH, true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function listQuery(string $list, bool $includeClosed = false, string $searchTerms = ''): array
    {
        $query = ReviewLists::query($list, $includeClosed, $searchTerms);
        self::assertNotNull($query);

        return $query;
    }
}
