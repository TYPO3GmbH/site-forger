<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\People\User;
use App\People\Users;
use PHPUnit\Framework\TestCase;

final class UsersLoginTest extends TestCase
{
    public function testTheUsernameDecidesBeforeTheEmail(): void
    {
        $benni = new User(320, 'Benni Mack', 'benni');

        self::assertSame($benni, Users::loginTarget('benni', $benni, [new User(1, 'Somebody else')]));
    }

    public function testTheOnePersonWithTheEmailAndNoUsernameIsTaken(): void
    {
        $taken = new User(320, 'Benni Mack', emails: ['benni@example.org']);
        $ofGerrit = new User(null, 'Benni', null, 7059, ['benni@example.org']);

        self::assertSame($taken, Users::loginTarget('benni', null, [$taken]));
        self::assertSame($ofGerrit, Users::loginTarget('benni', null, [$ofGerrit]));
        // Another account with the same address is not this one
        self::assertSame($taken, Users::loginTarget('benni', null, [new User(41, 'Other', 'other', emails: ['benni@example.org']), $taken]));
    }

    public function testNothingIsCreatedOrGuessed(): void
    {
        self::assertNull(Users::loginTarget('benni', null, []));
        self::assertNull(Users::loginTarget('benni', null, [new User(41, 'Other', 'other')]));
        // Several people without a username share the address: Forge tells which one it is
        self::assertNull(Users::loginTarget('benni', null, [new User(1, 'One'), new User(2, 'Two')]));
        self::assertNull(Users::loginTarget('forge:1', null, [new User(1, 'One')]));
        self::assertNull(Users::loginTarget('', null, [new User(1, 'One')]));
    }
}
