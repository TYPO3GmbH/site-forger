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
use App\Stream\ChangeActivity;
use App\Stream\IssueActivity;
use App\Stream\StreamEvents;
use App\Stream\StreamWriter;
use PHPUnit\Framework\TestCase;

/**
 * The backfill reads only the fields of IssueActivity and ChangeActivity. They must be enough for StreamEvents.
 */
final class ActivityFieldsTest extends TestCase
{
    public function testTheFieldsOfAnIssueAreEnoughToCloseIt(): void
    {
        $fields = new IssueActivity($this->createStub(StreamWriter::class))->fields();
        $document = array_intersect_key([
            'id' => 30000,
            'subject' => 'Old bug',
            'status' => ['id' => 5, 'name' => 'Closed'],
            'created_on' => '2011/03/01 10:00:00',
            'updated_on' => '2011/03/03 10:00:00',
            'closed_on' => '2011-03-02T10:00:00Z',
            'description' => 'Not read',
        ], array_flip($fields));

        $kinds = self::kinds(StreamEvents::ofIssue(Issue::fromDocument($document)));

        self::assertSame([StreamKindEnum::CREATED, StreamKindEnum::CLOSED], $kinds);
    }

    public function testTheFieldsOfAChangeAreEnoughToCloseIt(): void
    {
        $fields = new ChangeActivity($this->createStub(StreamWriter::class))->fields();
        $document = array_intersect_key([
            'id' => 946,
            'subject' => '[BUGFIX] Old fix',
            'reviewstatus' => 'ABANDONED',
            'created_on' => '2011/03/01 10:00:00',
            'updated_on' => '2011/03/01 12:00:00',
            'closed_on' => '2011/03/01 12:00:00',
            'messages' => [],
            'commit_message' => 'Not read',
        ], array_flip($fields));

        $kinds = self::kinds(StreamEvents::ofChange(Change::fromDocument($document)));

        self::assertSame([StreamKindEnum::CREATED, StreamKindEnum::CLOSED], $kinds);
    }

    /**
     * @param list<StreamEvent> $events
     *
     * @return list<StreamKindEnum>
     */
    private static function kinds(array $events): array
    {
        return array_map(static fn (StreamEvent $event): StreamKindEnum => $event->kind, $events);
    }
}
