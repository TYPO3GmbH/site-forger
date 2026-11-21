<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\ChainLink;
use App\Document\Change;
use App\Document\ReviewMessage;
use App\People\Person;
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

        self::assertEquals(
            new Person('Ingo Hollmann', gerritId: 52917, email: 'ingo@example.org'),
            $owner->ownerPerson(),
        );
        self::assertEquals(new Person('Benni Mack'), Change::fromDocument(['owner' => 'Benni Mack'])->ownerPerson());
        self::assertNull(Change::fromDocument(['owner' => ' '])->ownerPerson());
        self::assertNull(Change::fromDocument([])->ownerPerson());
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

    public function testThePlaceOfAChangeInItsChain(): void
    {
        $link = static fn (int $number): array => ['number' => $number, 'patch_set' => 1, 'current_patch_set' => 1];
        $change = Change::fromDocument(['id' => 2, 'subject' => 'This', 'chain' => [$link(3), $link(2), $link(1)]]);

        self::assertTrue($change->hasChain());
        self::assertSame([3], array_map(static fn ($item): int => $item->number, $change->chainAbove()));
        self::assertSame(2, $change->chainLink()?->number);
        self::assertSame([1], array_map(static fn ($item): int => $item->number, $change->chainBelow()));
        self::assertFalse(Change::fromDocument(['id' => 2, 'subject' => 'Alone', 'chain' => [$link(2)]])->hasChain());
    }

    public function testAChainLinkIsOutdatedAgainstTheIndex(): void
    {
        $link = ChainLink::fromDocument(['number' => 2, 'patch_set' => 3, 'current_patch_set' => 3]);

        self::assertFalse($link->isOutdated());
        // The change got a patch set since the chain was loaded
        self::assertTrue($link->isOutdated(Change::fromDocument(['id' => 2, 'subject' => 'Base', 'patchsets' => 4])));
    }

    public function testTheCommentsOfAMessageAndTheOpenThreads(): void
    {
        $comment = static fn (string $id, ?string $replyTo, string $message, bool $open, ?string $file): array => [
            'id' => $id,
            'in_reply_to' => $replyTo,
            'message_id' => $message,
            'unresolved' => $open,
            'file' => $file,
            'date' => '2026/11/20 10:00:0' . strlen($id),
        ];
        $change = Change::fromDocument(['id' => 1, 'subject' => 'Change', 'comments' => [
            $comment('a', null, 'm1', true, 'composer.json'),
            $comment('aa', 'a', 'm2', true, 'composer.json'),
            $comment('bbb', null, 'm2', true, null),
        ]]);
        $message = ReviewMessage::fromDocument(['id' => 'm2', 'message' => 'Patch Set 1:']);

        // The comment on the patch set before the one on a file
        $ids = array_map(static fn ($item): string => $item->id, $change->commentsOf($message));
        self::assertSame(['bbb', 'aa'], $ids);
        // A thread is open by its last comment
        self::assertSame([false, true, true], array_map($change->isOpen(...), $change->comments));
        self::assertSame(2, $change->commentCountOf('composer.json'));
        self::assertSame([], $change->commentsOf(ReviewMessage::fromDocument(['message' => 'No id'])));
    }
}
