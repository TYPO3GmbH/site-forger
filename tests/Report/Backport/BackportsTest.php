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
use App\Report\Backport\BackportRow;
use App\Report\Backport\Backports;
use App\Report\Backport\BackportVersion;
use Doctrine\Common\Collections\ArrayCollection;
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

        $rows = Backports::rows($backports, new ArrayCollection(array_map(Change::fromDocument(...), $changes)));

        // A missing backport before one in review, however old
        self::assertSame(['I2', 'I1'], $rows->map(static fn (BackportRow $row): string => $row->changeId)->toArray());
        $first = $rows->get(0);
        self::assertSame(1, $first?->missing);
        self::assertSame(96000, $first->change?->id);
        // The newest version first, the main change and the versions not targeted left out
        self::assertSame(
            [['version' => '14.3', 'state' => ReviewStateEnum::MERGED], ['version' => '13.4', 'state' => null]],
            $first->versions->map(static fn (BackportVersion $version): array => [
                'version' => $version->version,
                'state' => $version->state,
            ])->toArray(),
        );
        self::assertEquals(
            [new BackportVersion('14.3', Change::fromDocument($open))],
            $rows->get(1)?->versions->toArray(),
        );
        self::assertSame([96766, 96000, 95000], Backports::mainNumbers($backports));
    }

    public function testAChangeForgerDoesNotHaveStaysInTheList(): void
    {
        $rows = Backports::rows(
            ['I1' => ['mainReviewId' => 1, 'releases' => ['t3_134' => 'missing']]],
            new ArrayCollection(),
        );

        self::assertNull($rows->get(0)?->change);
        self::assertSame(1, $rows->get(0)?->number);
    }
}
