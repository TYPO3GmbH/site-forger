<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\GlobalSearch;
use PHPUnit\Framework\TestCase;

final class SearchTabFiltersTest extends TestCase
{
    public function testTheAuthorsAndAssigneesOfTheIssuesAreTheOwnersOfTheChanges(): void
    {
        $filters = [
            'author' => ['Benni_Mack' => 'true'],
            'assigned_to' => ['Benjamin_Kott' => 'true', 'Benni_Mack' => 'true', 'Unticked' => ''],
            'status' => ['New' => 'true'],
        ];

        self::assertSame(['owner' => ['Benni_Mack' => 'true', 'Benjamin_Kott' => 'true']], GlobalSearch::tabFilters(GlobalSearch::CHANGES, $filters));
    }

    public function testTheOwnersOfTheChangesAreTheAuthorsOfTheIssues(): void
    {
        self::assertSame(['author' => ['Benni_Mack' => 'true']], GlobalSearch::tabFilters(GlobalSearch::ISSUES, ['owner' => ['Benni_Mack' => 'true'], 'size' => ['Easy' => 'true']]));
    }

    public function testNoPeopleNoFilters(): void
    {
        self::assertSame([], GlobalSearch::tabFilters(GlobalSearch::ISSUES, ['size' => ['Easy' => 'true']]));
        self::assertSame([], GlobalSearch::tabFilters(GlobalSearch::CHANGES, ['author' => 'not a list']));
        self::assertSame([], GlobalSearch::tabFilters(GlobalSearch::CHANGES, []));
    }
}
