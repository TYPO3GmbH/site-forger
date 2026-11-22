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
use App\Enum\StreamMilestoneEnum;
use App\Enum\StreamNewcomerStateEnum;
use App\Search\NewcomerQuery;
use App\Search\StreamWindow;
use App\Stream\NewcomerFinder;
use App\Stream\StreamNewcomer;
use PHPUnit\Framework\TestCase;

/**
 * Where the newcomers decide something: which window a first counts for, the day of a first merge, and whether
 * somebody else answered a first issue. The states themselves are tested in StreamNewcomerStateEnumTest.
 */
final class NewcomersTest extends TestCase
{
    public function testAFirstCountsForTheWindowItFallsIn(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 12:00', new \DateTimeZone('UTC'));
        $window = StreamWindow::ofDays('2026-11-02 to 2026-11-08', $now);
        self::assertNotNull($window);
        $first = static fn (string $day, int $person): array => [
            'key' => 'Person ' . $person,
            NewcomerQuery::FIRST => ['value' => new \DateTimeImmutable($day . ' 10:00 UTC')->getTimestamp() * 1000],
        ];

        $tally = NewcomerFinder::tally([
            StreamMilestoneEnum::FIRST_ISSUE->value => [$first('2026-11-03', 1), $first('2026-10-30', 2)],
            StreamMilestoneEnum::FIRST_CHANGE->value => [$first('2026-11-07', 3), $first('2026-11-12', 4)],
            StreamMilestoneEnum::FIRST_MERGE->value => [$first('2026-11-05', 5), $first('2026-10-29', 6)],
        ], $window);

        // The issue and the change in the window. The merge is no contribution of its own.
        self::assertSame(2, $tally['contributions']);
        // The issue before the window, not the merge before it
        self::assertSame(1, $tally['previous']);
        self::assertSame(['first-issue' => 1, 'first-change' => 1, 'first-merge' => 1], $tally['milestones']);
        // The newest first. The change after the window that ended counts nowhere.
        self::assertSame(['Person 3', 'Person 5', 'Person 1'], array_column($tally['firsts'], 'person'));
    }

    public function testAFirstMergeTakesTheDayGerritSubmittedIt(): void
    {
        $change = [
            'id' => 7,
            'subject' => 'A fix',
            'owner' => 'Jane Doe',
            'reviewstatus' => 'MERGED',
            'updated_on' => '2026/11/09 08:00:00',
            'messages' => [[
                'date' => '2026/11/05 10:00:00',
                'author' => ['id' => 9, 'name' => 'John Merger'],
                'message' => 'Change has been successfully merged',
            ]],
        ];
        $merge = static fn (array $document): ?StreamNewcomer => StreamNewcomer::ofChange(
            StreamMilestoneEnum::FIRST_MERGE,
            Change::fromDocument($document),
        );

        $submitted = $merge([...$change, 'submitted' => '2026/11/05 09:59:58']);
        self::assertNotNull($submitted);
        self::assertSame('2026/11/05 09:59:58', $submitted->date);
        self::assertSame('John Merger', $submitted->mergedBy?->name);
        self::assertSame(StreamNewcomerStateEnum::MERGED, $submitted->state);
        // An older import has no "submitted": the message of the merge, then the last update
        self::assertSame('2026/11/05 10:00:00', $merge($change)?->date);
        self::assertSame('2026/11/09 08:00:00', $merge([...$change, 'messages' => []])?->date);
        // A change without an owner names nobody
        self::assertNull($merge([...$change, 'owner' => '']));
    }

    public function testAFirstIssueIsAnsweredWhenSomebodyElseWrote(): void
    {
        $issue = [
            'id' => 11,
            'subject' => 'A bug',
            'status' => ['id' => 1, 'name' => 'New'],
            'author' => ['id' => 1, 'name' => 'Jane Doe'],
            'created_on' => '2026/11/03 10:00:00',
        ];
        $note = static fn (string $name): array => [
            'id' => 1,
            'user' => ['id' => 'Jane Doe' === $name ? 1 : 2, 'name' => $name],
            'notes' => 'A note',
            'created_on' => '2026-11-04T10:00:00Z',
            'details' => [],
        ];
        $state = static fn (array $journals): ?StreamNewcomerStateEnum => StreamNewcomer::ofIssue(
            Issue::fromDocument([...$issue, 'journals' => $journals]),
        )?->state;

        self::assertSame(StreamNewcomerStateEnum::WAITING_FOR_ANSWER, $state([]));
        // Her own note is no answer
        self::assertSame(StreamNewcomerStateEnum::WAITING_FOR_ANSWER, $state([$note('Jane Doe')]));
        self::assertSame(StreamNewcomerStateEnum::ANSWERED, $state([$note('John Doe')]));
    }
}
