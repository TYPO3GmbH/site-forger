<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\CategoryOverview;
use PHPUnit\Framework\TestCase;

final class CategoryOverviewTest extends TestCase
{
    public function testTheCategoriesWithTheMostOpenIssuesComeFirst(): void
    {
        $rows = CategoryOverview::rows([
            ['key' => 'Caching', 'doc_count' => 1, 'tracker' => ['buckets' => [['key' => 'Bug', 'doc_count' => 1]]]],
            ['key' => 'Workspaces', 'doc_count' => 4, 'tracker' => ['buckets' => [['key' => 'Bug', 'doc_count' => 4]]]],
            ['key' => 'Fluid', 'doc_count' => 4, 'tracker' => ['buckets' => [['key' => 'Feature', 'doc_count' => 4]]]],
        ]);

        // The same counts by name
        self::assertSame(['Fluid', 'Workspaces', 'Caching'], array_column($rows, 'name'));
    }

    public function testEveryRowAddsUpTheRareTrackersInOther(): void
    {
        [$row] = CategoryOverview::rows([
            ['key' => 'Form Framework', 'doc_count' => 10, 'tracker' => ['buckets' => [
                ['key' => 'Task', 'doc_count' => 4],
                ['key' => 'Support', 'doc_count' => 1],
                ['key' => 'Bug', 'doc_count' => 2],
                ['key' => 'Retired tracker', 'doc_count' => 1],
                ['key' => 'Epic', 'doc_count' => 2],
            ]]],
        ]);

        self::assertSame(['Bug' => 2, 'Feature' => 0, 'Task' => 4, 'Story' => 0, 'Epic' => 2], $row['counts']);
        // None left out: a tracker Forge does not know any more counts, too
        self::assertSame(['count' => 2, 'labels' => ['Retired tracker', 'Support']], $row['other']);
        self::assertSame($row['total'], array_sum($row['counts']) + $row['other']['count']);
    }

    public function testTheSumsOfTheColumns(): void
    {
        $sums = CategoryOverview::sums(CategoryOverview::rows([
            ['key' => 'Fluid', 'doc_count' => 3, 'tracker' => ['buckets' => [['key' => 'Bug', 'doc_count' => 2], ['key' => 'Topic', 'doc_count' => 1]]]],
            ['key' => 'SEO', 'doc_count' => 2, 'tracker' => ['buckets' => [['key' => 'Bug', 'doc_count' => 1], ['key' => 'Task', 'doc_count' => 1]]]],
        ]));

        self::assertSame(['total' => 5, 'counts' => ['Bug' => 3, 'Feature' => 0, 'Task' => 1, 'Story' => 0, 'Epic' => 0], 'other' => 1], $sums);
    }

    public function testTheIssuesWithoutCategoryInARowOfTheirOwn(): void
    {
        $row = CategoryOverview::withoutCategory(['doc_count' => 3, 'tracker' => ['buckets' => [['key' => 'Bug', 'doc_count' => 2], ['key' => 'Support', 'doc_count' => 1]]]]);

        self::assertSame('Without category', $row['name']);
        self::assertSame(3, $row['total']);
        self::assertSame(2, $row['counts']['Bug']);
        // None, or no aggregation: no row
        self::assertNull(CategoryOverview::withoutCategory(['doc_count' => 0]));
        self::assertNull(CategoryOverview::withoutCategory(null));
        self::assertArrayHasKey('without_category', CategoryOverview::request()['aggregations']);
    }
}
