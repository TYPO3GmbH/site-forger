<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Report\Backport;

use App\Document\Change;
use App\Enum\ReviewStateEnum;
use App\Report\Backport\Backports;
use PHPUnit\Framework\TestCase;

final class BackportsTest extends TestCase
{
    public function testTheVersionsOfTheFileHaveTheirNames(): void
    {
        self::assertSame('13.4', Backports::versions()['t3_134']);
        self::assertSame('main', Backports::versions()['t3_main']);
        self::assertSame('6.2', Backports::versions()['t3_62']);
    }

    public function testARowHasTheVersionsToWaitForTheMissingOnesFirst(): void
    {
        $open = ['id' => 97836, 'reviewstatus' => 'NEW', 'branch' => '14.3'];
        $backports = [
            'I1' => ['mainReviewId' => 96766, 'releases' => ['t3_main' => 'tbd', 't3_143' => $open, 't3_134' => 'notTargeted']],
            'I2' => ['mainReviewId' => 96000, 'releases' => ['t3_main' => 'tbd', 't3_134' => 'missing', 't3_143' => ['id' => 96001, 'reviewstatus' => 'MERGED', 'branch' => '14.3']]],
            // Nothing to wait for: not in the list
            'I3' => ['mainReviewId' => 95000, 'releases' => ['t3_main' => 'tbd', 't3_134' => 'notTargeted']],
        ];
        $changes = [96766 => ['id' => 96766, 'updated_on' => '2026/11/19 10:00:00'], 96000 => ['id' => 96000, 'updated_on' => '2026/01/01 10:00:00']];

        $rows = Backports::rows($backports, array_map(Change::fromDocument(...), $changes));

        // A missing backport before one in review, however old
        self::assertSame(['I2', 'I1'], array_column($rows, 'changeId'));
        self::assertSame(1, $rows[0]['missing']);
        self::assertSame(96000, $rows[0]['change']?->id);
        // The newest version first, the main change and the versions not targeted left out
        self::assertSame(
            [['version' => '14.3', 'state' => ReviewStateEnum::MERGED], ['version' => '13.4', 'state' => null]],
            array_map(static fn (array $version): array => ['version' => $version['version'], 'state' => $version['state']], $rows[0]['versions']),
        );
        self::assertEquals([['version' => '14.3', 'state' => ReviewStateEnum::READY, 'change' => Change::fromDocument($open)]], $rows[1]['versions']);
        self::assertSame([96766, 96000, 95000], Backports::mainNumbers($backports));
    }

    public function testAChangeForgerDoesNotHaveStaysInTheList(): void
    {
        $rows = Backports::rows(['I1' => ['mainReviewId' => 1, 'releases' => ['t3_134' => 'missing']]], []);

        self::assertNull($rows[0]['change']);
        self::assertSame(1, $rows[0]['number']);
    }
}
