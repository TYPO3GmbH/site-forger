<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Api;

use App\Api\ActivityMapper;
use App\People\Person;
use App\People\UserDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ActivityMapperTest extends TestCase
{
    public function testThePersonHasTheUsernameOfTheIndexOfTheUsers(): void
    {
        $users = $this->createStub(UserDirectory::class);
        $users->method('keyOfPerson')->willReturn('jdoe');
        $users->method('shown')->willReturn(new Person('Jane Doe', forgeId: 7, gerritId: 9, username: 'jdoe'));
        $mapper = new ActivityMapper($this->createStub(UrlGeneratorInterface::class), $users);

        // The event knows the account on Forge only, by a short name, the index joined it with the one on Gerrit
        $person = $mapper->person(new Person('J. D.', forgeId: 7));

        self::assertSame(['Jane Doe', 'jdoe', 'jdoe', 9], [
            $person?->name,
            $person?->username,
            $person?->key,
            $person?->gerritId,
        ]);
    }

    public function testWithoutAnAccountThePersonKeepsTheUsernameOfTheEvent(): void
    {
        $users = $this->createStub(UserDirectory::class);
        $users->method('shown')->willReturnArgument(0);
        $mapper = new ActivityMapper($this->createStub(UrlGeneratorInterface::class), $users);

        self::assertSame('jroe', $mapper->person(new Person('John Roe', username: 'jroe'))?->username);
        self::assertNull($mapper->person(new Person('Nobody', forgeId: 3))?->username);
    }
}
