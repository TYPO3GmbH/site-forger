<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Twig;

use App\Elastic\Indices;
use App\People\UserDirectory;
use App\Twig\UserTwigExtension;
use Elastica\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class UserTwigExtensionTest extends TestCase
{
    public function testTheOwnerOfAChangeIsAUserWithNameAndEmail(): void
    {
        $extension = $this->extension();

        self::assertSame(['name' => 'Ingo Hollmann', 'email' => 'ingo@example.org', 'gerrit_id' => 52917], $extension->changeOwner(['owner' => 'Ingo Hollmann', 'ownerEmail' => 'ingo@example.org', 'owner_id' => 52917]));
        self::assertSame(['name' => 'Benni Mack', 'email' => null, 'gerrit_id' => null], $extension->changeOwner(['owner' => 'Benni Mack']));
        self::assertNull($extension->changeOwner(['owner' => ' ']));
        self::assertNull($extension->changeOwner([]));
    }

    public function testTheAvatarOfAnEmailElseThePlaceholder(): void
    {
        $extension = $this->extension();

        self::assertSame(
            '<img src="https://www.gravatar.com/avatar/' . md5('ingo@example.org') . '?s=40&amp;d=mp&amp;r=g" class="avatar" height="40" width="40" alt=""/>',
            (string) $extension->avatar(['name' => 'Ingo Hollmann', 'email' => ' Ingo@Example.org'], 40),
        );
        // Without an email the placeholder, every person alike
        self::assertStringContainsString(md5('') . '?s=40', (string) $extension->avatar(['name' => 'Somebody', 'email' => 'Unknown'], 40));
        self::assertStringContainsString(md5('') . '?s=40', (string) $extension->avatar(['name' => 'Somebody'], 40));
        self::assertStringContainsString(md5('') . '?s=36', (string) $extension->avatar(null));
    }

    private function extension(): UserTwigExtension
    {
        return new UserTwigExtension(new UserDirectory(new Indices(new Client()), new ArrayAdapter(), new NullLogger()));
    }
}
