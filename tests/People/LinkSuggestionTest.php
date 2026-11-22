<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\Enum\LinkReasonEnum;
use App\People\LinkSuggestion;
use App\People\User;
use PHPUnit\Framework\TestCase;

final class LinkSuggestionTest extends TestCase
{
    public function testTheNamesMatchWithoutRegardToCaseSpacesAndAccents(): void
    {
        $person = new User(1, 'René Häfliger', 'frontal');

        self::assertTrue(self::suggestion(new User(2, 'rene hafliger', 'old_frontal'), $person)->sameName());
        self::assertTrue(self::suggestion(new User(3, 'RenéHäfliger', 'old_frontal'), $person)->sameName());
        self::assertFalse(self::suggestion(new User(4, 'Klaus Muth', 'old_frontal'), $person)->sameName());
    }

    public function testAnyNameOfThePersonCounts(): void
    {
        // The name of Forge is a short form, the one of Gerrit the whole name
        $person = new User(2793, 'Ge Rie', 'just2b', 7342, gerritName: 'Georg Ringer');

        self::assertTrue(self::suggestion(new User(128, 'Georg Ringer'), $person)->sameName());
    }

    private static function suggestion(User $account, User $person): LinkSuggestion
    {
        return new LinkSuggestion($account, $person, LinkReasonEnum::RENAMED);
    }
}
