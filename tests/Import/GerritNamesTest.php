<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\Import\GerritNames;
use App\Search\GerritNameQuery;
use PHPUnit\Framework\TestCase;

final class GerritNamesTest extends TestCase
{
    public function testItTakesTheLatestNameOfEachAccount(): void
    {
        $names = GerritNames::namesOf(['buckets' => [
            self::account(7342, 'Georg Ringer'),
            self::account(9, ''),
        ]]);

        self::assertSame([7342 => 'Georg Ringer'], $names);
    }

    public function testItAsksForTheAccountsWithoutTools(): void
    {
        $request = GerritNameQuery::request([7342, 9]);

        self::assertSame(['terms' => ['author.gerrit_id' => [7342, 9]]], $request['query']['bool']['filter'][0]);
        self::assertSame(['term' => ['bot' => false]], $request['query']['bool']['filter'][1]);
        self::assertSame(2, $request['aggs'][GerritNameQuery::ACCOUNTS]['terms']['size']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function account(int $gerritId, string $name): array
    {
        return [
            'key' => $gerritId,
            'doc_count' => 1,
            GerritNameQuery::LATEST => ['hits' => ['hits' => [['_source' => ['author' => ['name' => $name]]]]]],
        ];
    }
}
