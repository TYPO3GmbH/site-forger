<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\Change;
use PHPUnit\Framework\TestCase;

final class ChangeTest extends TestCase
{
    public function testTheOwnerIsAUserWithNameAndEmail(): void
    {
        $owner = Change::fromDocument([
            'owner' => 'Ingo Hollmann',
            'ownerEmail' => 'ingo@example.org',
            'owner_id' => 52917,
        ]);

        self::assertSame(
            ['name' => 'Ingo Hollmann', 'email' => 'ingo@example.org', 'gerrit_id' => 52917],
            $owner->ownerAsUser(),
        );
        self::assertSame(
            ['name' => 'Benni Mack', 'email' => null, 'gerrit_id' => null],
            Change::fromDocument(['owner' => 'Benni Mack'])->ownerAsUser(),
        );
        self::assertNull(Change::fromDocument(['owner' => ' '])->ownerAsUser());
        self::assertNull(Change::fromDocument([])->ownerAsUser());
    }

    public function testAConflictNeedsAnOpenChangeGerritCannotMerge(): void
    {
        self::assertTrue(Change::fromDocument(['mergeable' => 'no', 'reviewstatus' => 'NEW'])->isConflict());
        self::assertTrue(Change::fromDocument(['mergeable' => 'no'])->isConflict());
        self::assertFalse(Change::fromDocument(['mergeable' => 'no', 'reviewstatus' => 'MERGED'])->isConflict());
        self::assertFalse(Change::fromDocument(['mergeable' => 'yes', 'reviewstatus' => 'NEW'])->isConflict());
        self::assertFalse(Change::fromDocument(['reviewstatus' => 'NEW'])->isConflict());
    }

    public function testNoTopicIsNoTopic(): void
    {
        self::assertTrue(Change::fromDocument(['topic' => 'cache'])->hasTopic());
        self::assertFalse(Change::fromDocument(['topic' => 'no topic'])->hasTopic());
        self::assertFalse(Change::fromDocument([])->hasTopic());
    }
}
