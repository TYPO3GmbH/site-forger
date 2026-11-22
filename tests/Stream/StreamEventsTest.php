<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Stream;

use App\Document\Change;
use App\Document\Issue;
use App\Document\StreamEvent;
use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;
use App\Stream\StreamEvents;
use PHPUnit\Framework\TestCase;

final class StreamEventsTest extends TestCase
{
    public function testAnIssueHasItsCreationAndItsNotes(): void
    {
        $events = StreamEvents::ofIssue(self::issue());

        self::assertSame(
            [
                ['forge-91000-created', StreamKindEnum::CREATED, 'Jane Doe', false, ['Bug']],
                ['forge-91000-11', StreamKindEnum::COMMENTED, 'John Roe', false, []],
                ['forge-91000-12', StreamKindEnum::CHANGED, 'Jane Doe', false, ['Priority: Should have → Must have']],
                ['forge-91000-13', StreamKindEnum::UPLOADED, 'Gerrit Code Review', true, []],
                ['forge-91000-15', StreamKindEnum::RESOLVED, 'John Roe', false, ['Status: Under Review → Resolved']],
            ],
            array_map(
                static fn (StreamEvent $event): array => [
                    $event->id,
                    $event->kind,
                    $event->author?->name,
                    $event->bot,
                    $event->summary,
                ],
                $events,
            ),
        );
        self::assertSame('2026/11/20 10:00:00', $events[1]->date);
        self::assertSame(3, $events[3]->patchSet);
    }

    public function testOnlyAFixResolvesAndOnlyAnOpenIssueCloses(): void
    {
        $issue = Issue::fromDocument([
            'id' => 91000,
            'subject' => 'Clear the cache',
            'created_on' => '2026/11/01 10:00:00',
            'journals' => [
                [
                    'id' => 21,
                    'user' => ['id' => 1, 'name' => 'Jane Doe'],
                    'created_on' => '2026-11-10T10:00:00Z',
                    // New to Rejected
                    'details' => [self::attribute('status_id', '1', '6')],
                ],
                [
                    'id' => 22,
                    'user' => ['id' => 1, 'name' => 'Jane Doe'],
                    'created_on' => '2026-11-11T10:00:00Z',
                    // Rejected to Closed
                    'details' => [self::attribute('status_id', '6', '5')],
                ],
            ],
        ]);

        $events = StreamEvents::ofIssue($issue);

        self::assertSame(
            [[StreamKindEnum::CREATED, 1], [StreamKindEnum::CLOSED, -1], [StreamKindEnum::CHANGED, 0]],
            array_map(static fn (StreamEvent $event): array => [$event->kind, $event->openDelta], $events),
        );
        self::assertSame(9 * 86400, $events[1]->duration);
        self::assertNull($events[2]->duration);
    }

    public function testAPrivateNoteIsNoEvent(): void
    {
        $ids = array_map(static fn (StreamEvent $event): string => $event->id, StreamEvents::ofIssue(self::issue()));

        self::assertNotContains('forge-91000-14', $ids);
    }

    public function testARelationNamesNoOtherIssue(): void
    {
        $issue = Issue::fromDocument([
            'id' => 91000,
            'subject' => 'Clear the cache',
            'journals' => [[
                'id' => 21,
                'user' => ['id' => 1, 'name' => 'Jane Doe'],
                'created_on' => '2026-11-20T10:00:00Z',
                'details' => [['property' => 'relation', 'name' => 'relates', 'new_value' => '93456']],
            ]],
        ]);

        $event = StreamEvents::ofIssue($issue)[0];

        self::assertSame(['Related to'], $event->summary);
        self::assertStringNotContainsString('93456', (string) json_encode($event->toDocument()));
    }

    public function testAChangeHasItsReviewLog(): void
    {
        $change = Change::fromDocument([
            'id' => 97000,
            'subject' => '[BUGFIX] Clear the cache',
            'branch' => '13.4',
            'messages' => [
                self::message('a1', 'Uploaded patch set 1.', 1, ['id' => 7, 'name' => 'Jane Doe']),
                self::message('a2', 'Patch Set 1: Verified+1', 1, ['id' => 49454, 'name' => 'core-ci']),
                self::message('a3', 'Uploaded patch set 2.', 2, ['id' => 7, 'name' => 'Jane Doe']),
                self::message('a4', "Patch Set 2: Code-Review+2\n\n(2 comments)", 2, ['id' => 8, 'name' => 'John Roe']),
                self::message('a5', 'Change has been successfully merged', 2, ['id' => 8, 'name' => 'John Roe']),
            ],
        ]);

        $events = StreamEvents::ofChange($change);

        self::assertSame(
            [
                [StreamKindEnum::CREATED, false, []],
                [StreamKindEnum::VOTED, true, ['Verified +1']],
                [StreamKindEnum::UPLOADED, false, []],
                [StreamKindEnum::VOTED, false, ['Code-Review +2', '2 comments']],
                [StreamKindEnum::MERGED, false, []],
            ],
            array_map(static fn (StreamEvent $event): array => [$event->kind, $event->bot, $event->summary], $events),
        );
        self::assertSame('gerrit-97000-a1', $events[0]->id);
        self::assertSame(StreamSourceEnum::GERRIT, $events[0]->source);
        self::assertSame('13.4', $events[0]->branch);
    }

    public function testAnAbandonEndsTheWaitLikeAMerge(): void
    {
        $change = Change::fromDocument([
            'id' => 97001,
            'subject' => '[BUGFIX] Clear the cache',
            'created_on' => '2026/11/17 10:00:00',
            'messages' => [
                self::message('a1', 'Uploaded patch set 1.', 1, ['id' => 7, 'name' => 'Jane Doe']),
                self::message('a2', 'Abandoned', 1, ['id' => 7, 'name' => 'Jane Doe']),
            ],
        ]);

        $events = StreamEvents::ofChange($change);

        self::assertSame(StreamKindEnum::CLOSED, $events[1]->kind);
        self::assertSame(3 * 86400, $events[1]->duration);
        self::assertNull($events[0]->duration);
    }

    public function testTheIndexKeepsNoEmail(): void
    {
        $change = Change::fromDocument([
            'id' => 97000,
            'subject' => 'Clear the cache',
            'messages' => [self::message('a1', 'Uploaded patch set 1.', 1, [
                'id' => 7,
                'name' => 'Jane Doe',
                'email' => 'jane@example.org',
            ])],
        ]);

        $document = StreamEvents::ofChange($change)[0]->toDocument();

        self::assertStringNotContainsString('example.org', (string) json_encode($document));
        self::assertSame(
            ['name' => 'Jane Doe', 'username' => null, 'forge_id' => null, 'gerrit_id' => 7],
            $document['author'],
        );
    }

    public function testAnEventOfAChangeKnowsTheOwnerWithoutTheEmail(): void
    {
        $change = Change::fromDocument([
            'id' => 97002,
            'subject' => 'Clear the cache',
            'owner' => 'Jane Doe',
            'owner_id' => 7,
            'owner_username' => 'jdoe',
            'ownerEmail' => 'jane@example.org',
            'messages' => [
                self::message('a1', 'Uploaded patch set 1.', 1, ['id' => 7, 'name' => 'Jane Doe']),
                self::message('a2', 'Patch Set 1: Code-Review+1', 1, ['id' => 8, 'name' => 'John Roe']),
            ],
        ]);

        $event = StreamEvents::ofChange($change)[1];
        $document = $event->toDocument();

        self::assertSame('John Roe', $event->author?->name);
        self::assertSame(
            ['name' => 'Jane Doe', 'username' => 'jdoe', 'forge_id' => null, 'gerrit_id' => 7],
            $document['owner'],
        );
        self::assertStringNotContainsString('example.org', (string) json_encode($document));
        self::assertEquals($event, StreamEvent::fromDocument($document));
    }

    public function testAnEventReadsBackFromTheIndex(): void
    {
        $event = StreamEvents::ofIssue(self::issue())[2];

        self::assertEquals($event, StreamEvent::fromDocument($event->toDocument()));
    }

    private static function issue(): Issue
    {
        return Issue::fromDocument([
            'id' => 91000,
            'subject' => 'Clear the cache',
            'tracker' => ['id' => 1, 'name' => 'Bug'],
            'author' => ['id' => 1, 'name' => 'Jane Doe'],
            'created_on' => '2026/11/19 09:00:00',
            'journals' => [
                [
                    'id' => 11,
                    'user' => ['id' => 2, 'name' => 'John Roe'],
                    'created_on' => '2026-11-20T10:00:00Z',
                    'notes' => 'Confirmed.',
                ],
                [
                    'id' => 12,
                    'user' => ['id' => 1, 'name' => 'Jane Doe'],
                    'created_on' => '2026-11-20T11:00:00Z',
                    'details' => [self::attribute('priority_id', '4', '3')],
                ],
                [
                    'id' => 13,
                    'user' => ['id' => 3, 'name' => 'Gerrit Code Review'],
                    'created_on' => '2026-11-20T12:00:00Z',
                    'notes' => 'Patch set 3 for branch *main* of project Packages/TYPO3.CMS has been pushed to the '
                        . 'review server. It is available at https://review.typo3.org/c/Packages/TYPO3.CMS/+/97000',
                ],
                [
                    'id' => 14,
                    'user' => ['id' => 2, 'name' => 'John Roe'],
                    'created_on' => '2026-11-20T13:00:00Z',
                    'notes' => 'Only for the security team.',
                    'private_notes' => true,
                ],
                [
                    'id' => 15,
                    'user' => ['id' => 2, 'name' => 'John Roe'],
                    'created_on' => '2026-11-20T14:00:00Z',
                    'notes' => 'Fixed.',
                    'details' => [self::attribute('status_id', '8', '3')],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $author
     *
     * @return array<string, mixed>
     */
    private static function message(string $id, string $text, int $patchSet, array $author): array
    {
        return [
            'id' => $id,
            'author' => $author,
            'date' => '2026/11/20 10:00:00',
            'patch_set' => $patchSet,
            'message' => $text,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attribute(string $name, string $from, string $to): array
    {
        return ['property' => 'attr', 'name' => $name, 'old_value' => $from, 'new_value' => $to];
    }
}
