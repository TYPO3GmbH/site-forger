<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Sprint;

use App\Sprint\BoardFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class BoardFilterTest extends TestCase
{
    public function testFiltersAreCombinedAndClosedIssuesHideAfterTheirDays(): void
    {
        $filter = new BoardFilter();
        $filter->categories = ['Form Framework', 'DataHandler', 'Form Framework'];
        $filter->fixedVersions = ['15.0'];
        $filter->excludedTrackers = ['Epic'];
        $filter->closedVisibleDays = 30;

        self::assertSame(
            ['bool' => [
                'must' => [
                    ['terms' => ['category.name' => ['DataHandler', 'Form Framework']]],
                    ['terms' => ['fixed_version.name' => ['15.0']]],
                ],
                'must_not' => [
                    ['terms' => ['tracker.name' => ['Epic']]],
                    ['bool' => ['must' => [
                        ['terms' => ['status.name' => ['Closed', 'Rejected', 'Resolved']]],
                        ['range' => ['updated_on' => ['lte' => 'now-30d']]],
                    ]]],
                ],
            ]],
            $filter->toQuery()
        );
    }

    public function testClosedIssuesCanBeHiddenAtOnceOrShownAlways(): void
    {
        $filter = new BoardFilter();
        $filter->tags = ['sprint'];

        $filter->closedVisibleDays = 0;
        self::assertSame(
            [['terms' => ['status.name' => ['Closed', 'Rejected', 'Resolved']]]],
            $filter->toQuery()['bool']['must_not']
        );

        $filter->closedVisibleDays = BoardFilter::CLOSED_ALWAYS_VISIBLE;
        self::assertArrayNotHasKey('must_not', $filter->toQuery()['bool']);
    }

    /**
     * @return iterable<string, array{\Closure(BoardFilter): void}>
     */
    public static function filters(): iterable
    {
        yield 'one filter' => [static function (BoardFilter $filter): void {
            $filter->trackers = ['Bug'];
        }];
        yield 'every filter' => [static function (BoardFilter $filter): void {
            $filter->trackers = ['Bug', 'Task'];
            $filter->categories = ['Backend User Interface'];
            $filter->fixedVersions = ['13.4.21', '14.0'];
            $filter->focuses = ['Remote Sprint'];
            $filter->tags = ['UX'];
            $filter->typo3Versions = ['13', '14'];
            $filter->excludedTrackers = ['Epic'];
            $filter->closedVisibleDays = 45;
        }];
        yield 'closed hidden at once' => [static function (BoardFilter $filter): void {
            $filter->focuses = ['Remote Sprint'];
            $filter->closedVisibleDays = 0;
        }];
        yield 'closed always shown' => [static function (BoardFilter $filter): void {
            $filter->categories = ['Localization'];
            $filter->closedVisibleDays = BoardFilter::CLOSED_ALWAYS_VISIBLE;
        }];
    }

    /**
     * @param \Closure(BoardFilter): void $configure
     */
    #[DataProvider('filters')]
    public function testTheWrittenQueryIsReadBack(\Closure $configure): void
    {
        $filter = new BoardFilter();
        $configure($filter);

        self::assertEquals($filter, BoardFilter::fromYaml($filter->toYaml()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function handWrittenQueries(): iterable
    {
        yield 'old board query' => [<<<'YAML'
            bool:
              should:
                - term: {fixed_version.id: 3528}
              must_not:
                bool:
                  must:
                    - range: {updated_on: {lte: now-30d}}
                  should:
                    - term: {status.name: Closed}
            YAML];
        yield 'field the wizard does not offer' => [<<<'YAML'
            bool:
              must:
                - terms: {priority.name: [Must have]}
            YAML];
        yield 'two fields in one clause' => [<<<'YAML'
            bool:
              must:
                - terms: {tracker.name: [Bug], category.name: [Backend]}
            YAML];
        yield 'additional option' => [<<<'YAML'
            bool:
              must:
                - terms: {tracker.name: [Bug]}
              minimum_should_match: 1
            YAML];
        yield 'values not in order' => [<<<'YAML'
            bool:
              must:
                - terms: {tracker.name: [Task, Bug]}
            YAML];
        yield 'no filter' => [<<<'YAML'
            bool:
              must_not:
                - terms: {tracker.name: [Epic]}
            YAML];
        yield 'invalid YAML' => ['bool: [must'];
        yield 'no mapping' => ['just text'];
    }

    #[DataProvider('handWrittenQueries')]
    public function testQueriesWrittenByHandAreNotRead(string $yaml): void
    {
        self::assertNull(BoardFilter::fromYaml($yaml));
    }

    public function testTheOnlyStatusesOfABoardAreReadBack(): void
    {
        $filter = new BoardFilter();
        $filter->statuses = ['New', 'Accepted'];
        $filter->closedVisibleDays = BoardFilter::CLOSED_ALWAYS_VISIBLE;

        self::assertTrue($filter->hasFilter());
        self::assertSame(
            ['bool' => ['must' => [['terms' => ['status.name' => ['Accepted', 'New']]]]]],
            $filter->toQuery(),
        );
        self::assertEquals(['Accepted', 'New'], BoardFilter::fromYaml($filter->toYaml())?->statuses);
    }

    public function testStatusesLeftOutAreReadBack(): void
    {
        $filter = BoardFilter::fromYaml(<<<'YAML'
            bool:
              must:
                - terms: {tracker.name: [Bug]}
              must_not:
                - terms: {status.name: [Closed]}
            YAML);

        self::assertNotNull($filter);
        self::assertSame(['Closed'], $filter->excludedStatuses);
        self::assertSame(BoardFilter::CLOSED_ALWAYS_VISIBLE, $filter->closedVisibleDays);
    }

    public function testSomeClosedStatesLeftOutKeepTheOthers(): void
    {
        // As the board of the contribution night had it: "Resolved" stays, "Closed" and "Rejected" go
        $import = BoardFilter::approximate(Yaml::parse(<<<'YAML'
            bool:
              must:
                bool:
                  must:
                    - term: {focus.name: On Location Sprint}
              must_not:
                bool:
                  should:
                    - term: {status.name: Closed}
                    - term: {status.name: Rejected}
            YAML));

        self::assertSame(['Closed', 'Rejected'], $import['filter']->excludedStatuses);
        self::assertSame(BoardFilter::CLOSED_ALWAYS_VISIBLE, $import['filter']->closedVisibleDays);
        self::assertSame([], $import['dropped']);
        self::assertEquals($import['filter'], BoardFilter::fromQuery($import['filter']->toQuery()));
    }

    public function testQueriesWrittenByHandAreTakenOverAsFarAsTheWizardKnowsThem(): void
    {
        $import = BoardFilter::approximate(Yaml::parse(<<<'YAML'
            bool:
              must:
                bool:
                  must:
                    - term: {focus.name: On Location Sprint}
                    - term: {category.id: 1107}
              should:
                - terms: {tags: [UX, a11y]}
              minimum_should_match: 1
              must_not:
                bool:
                  must:
                    - range: {updated_on: {lte: now-30d}}
                  should:
                    - term: {status.name: Closed}
                    - term: {status.name: Rejected}
                    - term: {status.name: Resolved}
            YAML));

        $expected = new BoardFilter();
        $expected->focuses = ['On Location Sprint'];
        $expected->tags = ['UX', 'a11y'];
        $expected->closedVisibleDays = 30;
        self::assertEquals($expected, $import['filter']);
        self::assertSame(['{ term: { category.id: 1107 } }'], $import['dropped']);
    }

    public function testClosedStatesLeftOutWithoutRangeHideClosedIssuesAtOnce(): void
    {
        $import = BoardFilter::approximate(Yaml::parse(<<<'YAML'
            bool:
              must:
                - term: {tracker.name: Bug}
              must_not:
                - term: {tracker.name: Epic}
                - bool:
                    should:
                      - term: {status.name: Closed}
                      - term: {status.name: Resolved}
                      - term: {status.name: Rejected}
            YAML));

        self::assertSame(['Bug'], $import['filter']->trackers);
        self::assertSame(['Epic'], $import['filter']->excludedTrackers);
        self::assertSame(0, $import['filter']->closedVisibleDays);
        self::assertSame([], $import['dropped']);
    }

    public function testSomeClosedStatesForSomeDaysAreReported(): void
    {
        // The filter keeps closed issues for some days only all together: the days of some of them are left out
        $import = BoardFilter::approximate(Yaml::parse(<<<'YAML'
            bool:
              must:
                - term: {tracker.name: Bug}
              must_not:
                bool:
                  must:
                    - range: {updated_on: {lte: now-30d}}
                  should:
                    - term: {status.name: Closed}
            YAML));

        self::assertSame(['Closed'], $import['filter']->excludedStatuses);
        self::assertSame(['{ range: { updated_on: { lte: now-30d } } }'], $import['dropped']);
    }

    public function testNegatedFiltersOnOtherFieldsAreLeftOut(): void
    {
        $import = BoardFilter::approximate(Yaml::parse(<<<'YAML'
            bool:
              must:
                - term: {fixed_version.name: '14.0'}
              must_not:
                - term: {category.name: Documentation}
                - term: {status.name: On Hold}
            YAML));

        self::assertSame(['14.0'], $import['filter']->fixedVersions);
        self::assertSame([], $import['filter']->categories);
        self::assertSame(['On Hold'], $import['filter']->excludedStatuses);
        self::assertSame(BoardFilter::CLOSED_ALWAYS_VISIBLE, $import['filter']->closedVisibleDays);
        self::assertSame(['{ term: { category.name: Documentation } }'], $import['dropped']);
    }

    public function testAnEmptyQueryStartsAFilterWithoutValues(): void
    {
        $filter = BoardFilter::fromYaml(null);

        self::assertEquals(new BoardFilter(), $filter);
        self::assertFalse($filter?->hasFilter());
    }
}
