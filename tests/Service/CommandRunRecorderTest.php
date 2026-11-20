<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Service;

use App\Enum\CommandRunStatusEnum;
use App\Service\CommandRunRecorder;
use App\Service\JobStopped;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CommandRunRecorderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $updates = [];

    public function testARunAskedToStopStopsAtItsHeartbeatAsAsked(): void
    {
        $recorder = $this->recorder(['stop_status' => 'interrupted', 'stop_reason' => 'Interrupted: a deployment']);
        $recorder->start('app:import --mode=reviewFull');

        try {
            // A new phase writes a heartbeat at once
            $recorder->progress('Listing Gerrit changes', 500, 90000);
            self::fail('The run did not stop');
        } catch (JobStopped $stopped) {
            self::assertSame('Interrupted: a deployment', $stopped->getMessage());
        }
        $recorder->finish(1);

        $last = array_last($this->updates);
        self::assertSame(CommandRunStatusEnum::INTERRUPTED->value, $last['status']);
        self::assertSame('Interrupted: a deployment', $last['message']);
        // Where it got, and since when it was in its phase
        self::assertSame('Listing Gerrit changes', $last['phase']);
        self::assertInstanceOf(\DateTimeImmutable::class, $last['phase_started_at']);
    }

    public function testAWaitForARateLimitStopsWithinSecondsWhenTheRunIsAskedToStop(): void
    {
        $recorder = $this->recorder(['stop_status' => 'cancelled', 'stop_reason' => 'Cancelled by dev-admin']);
        $recorder->start('app:import --mode=forgeFull');
        $slept = 0;

        try {
            $recorder->sleep(300, static function (int $seconds) use (&$slept): void {
                $slept += $seconds;
            });
            self::fail('The run did not stop');
        } catch (JobStopped $stopped) {
            self::assertSame('Cancelled by dev-admin', $stopped->getMessage());
        }

        self::assertLessThanOrEqual(2, $slept);
    }

    public function testARunNotAskedToStopGoesOn(): void
    {
        $recorder = $this->recorder(['stop_status' => null, 'stop_reason' => null]);
        $recorder->start('app:import --mode=forgeDelta');

        $recorder->progress('Importing changed Forge issues', 1, 10);
        $recorder->finish(0);

        self::assertSame(CommandRunStatusEnum::SUCCESS->value, array_last($this->updates)['status']);
    }

    public function testAWaitForARateLimitIsANoteOnTheStepNotAStepOfItsOwn(): void
    {
        $recorder = $this->recorder(['stop_status' => null, 'stop_reason' => null]);
        $recorder->start('app:import --mode=forgeFull');

        $recorder->progress('Going through all Forge issues', 100, 1000);
        $start = array_last($this->updates)['phase_started_at'];
        $recorder->waiting('Forge rate limit, waiting 30 s (attempt 1 of 6)');

        $waiting = $this->steps();
        self::assertCount(1, $waiting);
        self::assertSame('Forge rate limit, waiting 30 s (attempt 1 of 6)', $waiting[0]['waiting']);
        self::assertSame('Going through all Forge issues', array_last($this->updates)['phase']);

        // Going on ends the wait, the step keeps its start
        $recorder->progress('Going through all Forge issues', 101, 1000);
        $recorder->finish(0);
        self::assertSame($start, array_last($this->updates)['phase_started_at']);
        self::assertNull($this->steps()[0]['waiting']);
    }

    public function testEachStepIsRecordedWithHowFarItGotAndWhenItEnded(): void
    {
        $recorder = $this->recorder(['stop_status' => null, 'stop_reason' => null]);
        $recorder->start('app:import --mode=reviewFull');

        $recorder->progress('Listing Gerrit changes', 500, null);
        $recorder->progress('Listing Gerrit changes', 59000, null);
        $recorder->progress('Importing Gerrit changes', 12, 40);
        $recorder->finish(0);

        $steps = $this->steps();
        self::assertSame(['Listing Gerrit changes', 'Importing Gerrit changes'], array_column($steps, 'name'));
        self::assertSame(59000, $steps[0]['current']);
        self::assertNotNull($steps[0]['endedAt']);
        self::assertSame(12, $steps[1]['current']);
        self::assertSame(40, $steps[1]['total']);
        // The end of the run ends its last step
        self::assertNotNull($steps[1]['endedAt']);
    }

    /**
     * The steps of the last update, as CommandRun reads them.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        $json = array_last($this->updates)['steps'];
        self::assertIsString($json);

        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $stop what the run reads of its row at a heartbeat
     */
    private function recorder(array $stop): CommandRunRecorder
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('lastInsertId')->willReturn('7');
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAssociative')->willReturn($stop);
        $connection->method('update')->willReturnCallback(function (string $table, array $values): int {
            $this->updates[] = $values;

            return 1;
        });

        return new CommandRunRecorder($connection, new NullLogger());
    }
}
