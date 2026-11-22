<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Document\Change;
use App\Document\Issue;
use App\Enum\StreamNewcomerStateEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreamNewcomerStateEnumTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>|null, bool, StreamNewcomerStateEnum}>
     */
    public static function changes(): iterable
    {
        $open = ['id' => 97000, 'subject' => 'Clear the cache', 'reviewstatus' => 'NEW', 'mergeable' => 'yes'];

        yield 'not imported yet' => [null, false, StreamNewcomerStateEnum::WAITING];
        yield 'no review' => [$open, false, StreamNewcomerStateEnum::WAITING];
        yield 'a review' => [$open, true, StreamNewcomerStateEnum::IN_REVIEW];
        yield 'a -1' => [
            [...$open, 'label_states' => ['Code-Review' => 'REJECT']],
            true,
            StreamNewcomerStateEnum::NEEDS_WORK,
        ];
        yield 'a conflict before a -1' => [
            [...$open, 'mergeable' => 'no', 'label_states' => ['Code-Review' => 'REJECT']],
            true,
            StreamNewcomerStateEnum::CONFLICT,
        ];
        yield 'work in progress' => [
            [...$open, 'work_in_progress' => true],
            false,
            StreamNewcomerStateEnum::WORK_IN_PROGRESS,
        ];
        yield 'merged' => [[...$open, 'reviewstatus' => 'MERGED'], true, StreamNewcomerStateEnum::MERGED];
        yield 'abandoned' => [[...$open, 'reviewstatus' => 'ABANDONED'], false, StreamNewcomerStateEnum::ABANDONED];
    }

    /**
     * @param array<string, mixed>|null $document
     */
    #[DataProvider('changes')]
    public function testTheStateFollowsTheChange(?array $document, bool $reviewed, StreamNewcomerStateEnum $state): void
    {
        $change = null === $document ? null : Change::fromDocument($document);

        self::assertSame($state, StreamNewcomerStateEnum::ofChange($change, $reviewed));
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, bool, StreamNewcomerStateEnum}>
     */
    public static function issues(): iterable
    {
        $new = ['id' => 91000, 'subject' => 'Something is broken', 'status' => ['id' => 1, 'name' => 'New']];

        yield 'not imported yet' => [null, false, StreamNewcomerStateEnum::WAITING_FOR_ANSWER];
        yield 'no answer' => [$new, false, StreamNewcomerStateEnum::WAITING_FOR_ANSWER];
        yield 'an answer' => [$new, true, StreamNewcomerStateEnum::ANSWERED];
        yield 'accepted' => [
            [...$new, 'status' => ['id' => 2, 'name' => 'Accepted']],
            false,
            StreamNewcomerStateEnum::ANSWERED,
        ];
        yield 'a change in review' => [
            [...$new, 'status' => ['id' => 8, 'name' => 'Under Review']],
            false,
            StreamNewcomerStateEnum::IN_REVIEW,
        ];
        yield 'needs feedback' => [
            [...$new, 'status' => ['id' => 4, 'name' => 'Needs Feedback']],
            true,
            StreamNewcomerStateEnum::NEEDS_FEEDBACK,
        ];
        yield 'resolved' => [
            [...$new, 'status' => ['id' => 3, 'name' => 'Resolved']],
            true,
            StreamNewcomerStateEnum::RESOLVED,
        ];
        yield 'rejected' => [
            [...$new, 'status' => ['id' => 6, 'name' => 'Rejected']],
            false,
            StreamNewcomerStateEnum::CLOSED,
        ];
    }

    /**
     * @param array<string, mixed>|null $document
     */
    #[DataProvider('issues')]
    public function testTheStateFollowsTheIssue(?array $document, bool $answered, StreamNewcomerStateEnum $state): void
    {
        $issue = null === $document ? null : Issue::fromDocument($document);

        self::assertSame($state, StreamNewcomerStateEnum::ofIssue($issue, $answered));
    }

    public function testOnlyWhatWaitsNeedsHelp(): void
    {
        $needsHelp = array_filter(
            StreamNewcomerStateEnum::cases(),
            static fn (StreamNewcomerStateEnum $state): bool => $state->needsHelp(),
        );

        self::assertSame(
            [
                StreamNewcomerStateEnum::WAITING_FOR_ANSWER,
                StreamNewcomerStateEnum::NEEDS_FEEDBACK,
                StreamNewcomerStateEnum::WAITING,
                StreamNewcomerStateEnum::NEEDS_WORK,
                StreamNewcomerStateEnum::CONFLICT,
            ],
            array_values($needsHelp),
        );
    }
}
