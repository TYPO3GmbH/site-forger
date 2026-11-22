<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\Document\Change;
use App\Document\Issue;
use App\Elastic\SearchResult;
use App\People\PersonalRows;
use PHPUnit\Framework\TestCase;

final class PersonalRowsTest extends TestCase
{
    public function testACardTakesItsRowsInTurnFromItsLists(): void
    {
        $rows = new PersonalRows(3);

        $card = $rows->take(self::changes(1, 2, 3), self::issues(7, 8));

        self::assertSame(['change:1', 'issue:7', 'change:2'], self::keys($card));
    }

    public function testACardLeavesOutWhatACardBeforeItShows(): void
    {
        $rows = new PersonalRows(3);
        $rows->take(self::changes(1, 2, 3));

        $card = $rows->take(self::changes(2, 3, 4, 5), self::issues(7));

        self::assertSame(['change:4', 'issue:7', 'change:5'], self::keys($card));
    }

    public function testAChangeAndAnIssueWithTheSameNumberAreNotTheSame(): void
    {
        $rows = new PersonalRows(3);
        $rows->take(self::changes(1));

        self::assertSame(['issue:1'], self::keys($rows->take(self::issues(1))));
    }

    public function testTheListsOfALaterCardLoadMoreThanItsRows(): void
    {
        $rows = new PersonalRows(3);
        self::assertSame(3, $rows->candidates());

        $rows->take(self::changes(1, 2));

        self::assertSame(5, $rows->candidates());
    }

    public function testACardWithoutDocumentsHasNoRows(): void
    {
        $rows = new PersonalRows(3);

        self::assertSame([], $rows->take(self::changes(), self::issues()));
        self::assertSame([], $rows->take());
    }

    /**
     * @return SearchResult<Change>
     */
    private static function changes(int ...$ids): SearchResult
    {
        return SearchResult::ofDocuments(array_values(array_map(
            static fn (int $id): Change => Change::fromDocument(['id' => $id, 'subject' => 'Change ' . $id]),
            $ids,
        )));
    }

    /**
     * @return SearchResult<Issue>
     */
    private static function issues(int ...$ids): SearchResult
    {
        return SearchResult::ofDocuments(array_values(array_map(
            static fn (int $id): Issue => Issue::fromDocument(['id' => $id, 'subject' => 'Issue ' . $id]),
            $ids,
        )));
    }

    /**
     * @param list<array{kind: string, item: Change|Issue}> $rows
     *
     * @return list<string>
     */
    private static function keys(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['kind'] . ':' . $row['item']->id, $rows);
    }
}
