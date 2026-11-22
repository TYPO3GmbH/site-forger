<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\Import\GerritAccountRepair;
use App\People\User;
use PHPUnit\Framework\TestCase;

final class GerritAccountRepairTest extends TestCase
{
    public function testTheAccountStaysWithItsUsernameOnly(): void
    {
        $owner = new User(null, '', 'just2b', 7342, gerritName: 'Georg Ringer');
        $other = new User(2793, 'Ge Rie', 'georg.r', 7342, gerritName: 'Georg Ringer');

        $repaired = GerritAccountRepair::repaired([$owner, $other], [7342 => 'just2b']);

        self::assertCount(1, $repaired);
        self::assertSame(['georg.r', null, null, 2793], [
            $repaired[0]->username,
            $repaired[0]->gerritId,
            $repaired[0]->gerritName,
            $repaired[0]->forgeId,
        ]);
    }

    public function testWithoutAUsernameInTheActivityNoUserKeepsTheAccount(): void
    {
        $users = [new User(45156, 'Sabrina Hess', 'sabrinahess', 52510), new User(37556, 'Steffen Hastädt', 'sh78', 52510)];

        self::assertSame(
            [null, null],
            array_map(static fn (User $user): ?int => $user->gerritId, GerritAccountRepair::repaired($users, [])),
        );
    }
}
