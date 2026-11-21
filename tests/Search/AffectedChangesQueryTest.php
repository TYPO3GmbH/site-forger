<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Search\AffectedChangesQuery;
use PHPUnit\Framework\TestCase;

final class AffectedChangesQueryTest extends TestCase
{
    public function testFindsTheOpenChangesOnTheBranchOfAMergedChangeThatTouchItsFiles(): void
    {
        $request = AffectedChangesQuery::request([[
            'id' => 1,
            'reviewstatus' => 'MERGED',
            'branch' => 'main',
            'files' => ['composer.json'],
        ]], 200);

        self::assertNotNull($request);
        $bool = $request['query']['bool'];
        self::assertContains(['bool' => ['filter' => [
            ['term' => ['branch' => 'main']],
            ['terms' => ['files' => ['composer.json']]],
        ]]], $bool['should']);
        self::assertSame([['term' => ['reviewstatus' => 'NEW']]], $bool['filter']);
        // Not the imported changes themselves
        self::assertSame([['terms' => ['id' => [1]]]], $bool['must_not']);
        self::assertSame(200, $request['size']);
    }

    public function testFindsTheChainTheMergeConflictsAndTheChangesThatNamedAnImportedOneAsConflict(): void
    {
        $request = AffectedChangesQuery::request([[
            'id' => 2,
            'reviewstatus' => 'NEW',
            'chain' => [['number' => 3], ['number' => 2]],
            'merge_conflicts' => [4],
        ]], 200);

        self::assertNotNull($request);
        $should = $request['query']['bool']['should'];
        self::assertContains(['terms' => ['id' => [3, 2, 4]]], $should);
        self::assertContains(['terms' => ['merge_conflicts' => [2]]], $should);
    }

    public function testHasNoRequestWithoutImportedChanges(): void
    {
        self::assertNull(AffectedChangesQuery::request([], 200));
    }
}
