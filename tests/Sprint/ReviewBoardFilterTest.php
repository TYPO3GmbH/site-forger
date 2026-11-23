<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Sprint;

use App\Entity\SprintBoard;
use App\Enum\BoardTypeEnum;
use App\Sprint\ReviewBoardFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReviewBoardFilterTest extends TestCase
{
    private const array OPEN = ['query_string' => ['default_field' => 'reviewstatus', 'query' => 'NEW']];

    private const array NO_WORK_IN_PROGRESS = [['term' => ['work_in_progress' => true]]];

    public function testTheFiltersAreCombinedAndWorkInProgressIsLeftOut(): void
    {
        $filter = new ReviewBoardFilter();
        $filter->tags = ['BUGFIX', 'TASK'];
        $filter->branches = ['main'];
        $filter->owners = ['kasper'];

        self::assertSame(
            ['bool' => [
                'filter' => [
                    self::OPEN,
                    ['terms' => ['reviewtags' => ['BUGFIX', 'TASK']]],
                    ['terms' => ['branch' => ['main']]],
                    ['terms' => ['owner_username' => ['kasper']]],
                ],
                'must_not' => self::NO_WORK_IN_PROGRESS,
            ]],
            $filter->toQuery(),
        );
    }

    public function testAChangeMatchesAnyOfThePaths(): void
    {
        $filter = new ReviewBoardFilter();
        $filter->paths = ['typo3/sysext/form/', '*.rst'];
        $filter->workInProgress = true;

        $query = $filter->toQuery();

        self::assertArrayNotHasKey('must_not', $query['bool']);
        self::assertCount(2, $query['bool']['filter'][1]['bool']['should']);
        self::assertSame(1, $query['bool']['filter'][1]['bool']['minimum_should_match']);
    }

    public function testAFilterOfTheIssuesIsAFilterButNotPartOfTheQuery(): void
    {
        $filter = new ReviewBoardFilter();
        $filter->issueQuery = "bool:\n  must:\n    - terms: { tracker.name: [Bug] }\n";

        self::assertTrue($filter->hasFilter());
        self::assertSame([self::OPEN], $filter->toQuery()['bool']['filter']);
    }

    public function testAChangeHasAnyOfTheSizesAndOfTheStages(): void
    {
        $filter = new ReviewBoardFilter();
        $filter->sizes = ['Easy', 'Normal', 'No size'];
        $filter->activities = ['Stale'];

        $clauses = $filter->toQuery()['bool']['filter'];

        self::assertTrue($filter->hasFilter());
        self::assertCount(3, $clauses);
        self::assertCount(2, $clauses[1]['bool']['should']);
        self::assertSame(1, $clauses[1]['bool']['minimum_should_match']);
        self::assertCount(1, $clauses[2]['bool']['should']);
        self::assertSame(['sizes' => ['Easy', 'Normal', 'No size'], 'activities' => ['Stale']], $filter->toArray());
    }

    public function testAnEmptyQueryOfTheIssuesIsNoFilter(): void
    {
        $filter = new ReviewBoardFilter();
        $filter->tags = ['BUGFIX'];
        // What the form sets when no filter of the issues is picked
        $filter->issueQuery = '';

        self::assertFalse($filter->filtersIssues());
        self::assertSame(['tags' => ['BUGFIX']], $filter->toArray());
    }

    public function testAFilterWithoutValuesIsNone(): void
    {
        $filter = new ReviewBoardFilter();
        $filter->workInProgress = true;

        self::assertFalse($filter->hasFilter());
        self::assertSame(['workInProgress' => true], $filter->toArray());
    }

    public function testTheFiltersComeBackAsTheyWereStored(): void
    {
        $filter = new ReviewBoardFilter();
        $filter->paths = ['typo3/sysext/form/'];
        $filter->topics = ['a11y'];
        $filter->issueQuery = 'match_all: {}';

        $stored = $filter->toArray();

        self::assertSame(
            ['paths' => ['typo3/sysext/form/'], 'topics' => ['a11y'], 'issueQuery' => 'match_all: {}'],
            $stored,
        );
        self::assertEquals($filter, ReviewBoardFilter::fromArray($stored));
    }

    public function testStoredValuesAreCleaned(): void
    {
        $filter = ReviewBoardFilter::fromArray(['tags' => [' BUGFIX ', '', 'BUGFIX', ['x']], 'issueQuery' => ' ']);

        self::assertSame(['BUGFIX'], $filter->tags);
        self::assertNull($filter->issueQuery);
        self::assertEquals(new ReviewBoardFilter(), ReviewBoardFilter::fromArray(null));
    }

    public function testABoardOfReviewsWritesItsQueryWithItsFilters(): void
    {
        $filter = new ReviewBoardFilter();
        $filter->tags = ['FEATURE'];
        $board = (new SprintBoard())->setType(BoardTypeEnum::REVIEWS)->setReviewFilter($filter);

        self::assertSame(BoardTypeEnum::ISSUES, (new SprintBoard())->getType());
        // A board opens with its issues unless it says otherwise
        self::assertSame(BoardTypeEnum::ISSUES, (new SprintBoard())->getFirstView());
        self::assertEquals($filter, $board->getReviewFilter());
        self::assertSame($filter->toQuery(), Yaml::parse((string) $board->getQuery()));
    }
}
