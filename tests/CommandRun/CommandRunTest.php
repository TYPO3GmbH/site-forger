<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\CommandRun;

use App\DTO\CommandJobOverview;
use App\DTO\CommandRunStep;
use App\Entity\CommandRun;
use App\Enum\CommandRunStatusEnum;
use App\Import\ForgeImport;
use App\Import\GerritImport;
use App\Scheduler\WeeklyTrigger;
use App\Subscriber\CommandRunSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class CommandRunTest extends TestCase
{
    private const array GERRIT_FULL = [GerritImport::STEP_FULL_LIST, GerritImport::STEP_FULL_LOAD, GerritImport::STEP_VANISHED];

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-19 16:00:00');
    }

    public function testJobNamesTheOptionsThatDifferFromTheirDefaults(): void
    {
        // The arguments and options of app:import:review
        $definition = new InputDefinition([
            new InputArgument('changes', InputArgument::IS_ARRAY | InputArgument::OPTIONAL),
            new InputOption('full', null, InputOption::VALUE_NONE),
            new InputOption('start-at', null, InputOption::VALUE_REQUIRED, '', '0'),
            new InputOption('verbose', 'v', InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--start-at' => '0', '--verbose' => true], $definition);
        self::assertSame('app:import:review', CommandRunSubscriber::job('app:import:review', $input, $definition, ['verbose']));

        $input = new ArrayInput(['--full' => true, '--start-at' => '500'], $definition);
        self::assertSame('app:import:review --full --start-at=500', CommandRunSubscriber::job('app:import:review', $input, $definition));

        $input = new ArrayInput(['changes' => ['95026', '95027']], $definition);
        self::assertSame('app:import:review 95026 95027', CommandRunSubscriber::job('app:import:review', $input, $definition));
    }

    public function testJobWithoutOptions(): void
    {
        $definition = new InputDefinition();
        self::assertSame('app:backports', CommandRunSubscriber::job('app:backports', new ArrayInput([], $definition), $definition));
    }

    public function testARunWithoutHeartbeatIsAborted(): void
    {
        self::assertSame(CommandRunStatusEnum::RUNNING, $this->commandRun('-5 minutes', CommandRunStatusEnum::RUNNING, heartbeat: '-1 minute')->getStatus($this->now));
        self::assertSame(CommandRunStatusEnum::ABORTED, $this->commandRun('-2 hours', CommandRunStatusEnum::RUNNING, heartbeat: '-1 hour')->getStatus($this->now));
        self::assertSame(CommandRunStatusEnum::SUCCESS, $this->commandRun('-2 hours', CommandRunStatusEnum::SUCCESS)->getStatus($this->now));
    }

    public function testAJobRunningAsPlannedIsNotOverdue(): void
    {
        $overview = $this->overview(['-2 minutes', '-7 minutes', '-12 minutes', '-17 minutes']);

        self::assertFalse($overview->overdue);
        self::assertSame(0, $overview->failures);
        self::assertSame('every 5 minutes', $overview->schedule);
        self::assertSame('2026-11-19 16:05', $overview->nextRun?->format('Y-m-d H:i'));
    }

    public function testAJobTheScheduleNoLongerStartsIsOverdue(): void
    {
        // The run planned after the last start is more than the grace of 10 minutes late
        self::assertTrue($this->overview(['-17 minutes', '-22 minutes'])->overdue);
        self::assertFalse($this->overview(['-12 minutes', '-17 minutes'])->overdue);
    }

    public function testAJobThatKeepsFailingIsOverdue(): void
    {
        $overview = $this->overview(
            ['-2 minutes', '-7 minutes', '-12 minutes', '-17 minutes', '-22 minutes', '-27 minutes'],
            [CommandRunStatusEnum::FAILED, CommandRunStatusEnum::FAILED, CommandRunStatusEnum::FAILED, CommandRunStatusEnum::FAILED, CommandRunStatusEnum::FAILED, CommandRunStatusEnum::SUCCESS],
        );

        self::assertSame(5, $overview->failures);
        self::assertTrue($overview->overdue);
        self::assertSame(CommandRunStatusEnum::SUCCESS, $overview->lastSuccess?->getStatus($this->now));
    }

    public function testAFailedRunIsNoReasonYet(): void
    {
        $overview = $this->overview(['-2 minutes', '-7 minutes'], [CommandRunStatusEnum::FAILED, CommandRunStatusEnum::SUCCESS]);

        self::assertFalse($overview->overdue);
        self::assertTrue($overview->needsAttention($this->now));
    }

    public function testAJobSkippedForTheLockOfAnotherRunIsNotOverdue(): void
    {
        // reviewDelta while reviewFull holds the Gerrit lock
        $overview = $this->overview(
            ['-2 minutes', '-7 minutes', '-12 minutes', '-17 minutes', '-22 minutes', '-27 minutes', '-32 minutes'],
            [CommandRunStatusEnum::LOCKED, CommandRunStatusEnum::LOCKED, CommandRunStatusEnum::LOCKED, CommandRunStatusEnum::LOCKED, CommandRunStatusEnum::LOCKED, CommandRunStatusEnum::LOCKED, CommandRunStatusEnum::SUCCESS],
        );

        self::assertFalse($overview->overdue);
        self::assertSame(0, $overview->failures);
    }

    public function testAJobThatIsAlwaysLockedIsOverdueAfterAWhile(): void
    {
        $starts = [];
        for ($i = 0; $i < 30; ++$i) {
            $starts[] = '-' . (2 + $i * 30) . ' minutes';
        }
        $statuses = array_fill(0, 29, CommandRunStatusEnum::LOCKED);
        $statuses[] = CommandRunStatusEnum::SUCCESS;

        self::assertTrue($this->overview($starts, $statuses)->overdue);
    }

    public function testTheSpeedAndTheEndOfAPhase(): void
    {
        // 30,000 of 90,000 changes listed in the 10 minutes since the phase started
        $run = $this->commandRun('-40 minutes', CommandRunStatusEnum::RUNNING, heartbeat: '-10 seconds', more: [
            'phase' => 'Listing Gerrit changes',
            'phaseStartedAt' => $this->now->modify('-10 minutes -10 seconds'),
            'progressCurrent' => 30000,
            'progressTotal' => 90000,
        ]);

        self::assertSame(3000.0, $run->getProgressPerMinute());
        // 60,000 left at 3,000 a minute: 20 minutes after the last heartbeat
        self::assertSame('16:19:50', $run->getPhaseEndsAt()?->format('H:i:s'));
    }

    public function testNoSpeedForTheFirstSecondsOrWithoutProgress(): void
    {
        $young = $this->commandRun('-1 minute', CommandRunStatusEnum::RUNNING, heartbeat: '-5 seconds', more: ['phaseStartedAt' => $this->now->modify('-15 seconds'), 'progressCurrent' => 10, 'progressTotal' => 100]);
        $nothing = $this->commandRun('-1 minute', CommandRunStatusEnum::RUNNING, heartbeat: '-5 seconds', more: ['phaseStartedAt' => $this->now->modify('-1 minute'), 'progressCurrent' => 0, 'progressTotal' => 100]);
        // A speed, but no total: no end
        $open = $this->commandRun('-1 minute', CommandRunStatusEnum::RUNNING, heartbeat: '-5 seconds', more: ['phaseStartedAt' => $this->now->modify('-1 minute'), 'progressCurrent' => 500]);

        self::assertNull($young->getProgressPerMinute());
        self::assertNull($nothing->getProgressPerMinute());
        self::assertNotNull($open->getProgressPerMinute());
        self::assertNull($open->getPhaseEndsAt());
    }

    public function testTheStepsOfARunningRunIncludeThoseStillToCome(): void
    {
        $run = $this->commandRun('-10 minutes', CommandRunStatusEnum::RUNNING, heartbeat: '-5 seconds', more: [
            'steps' => [
                $this->step(GerritImport::STEP_FULL_LIST, '-10 minutes', '-6 minutes', 59000),
                $this->step(GerritImport::STEP_FULL_LOAD, '-6 minutes', null, 1200, 4000, 'Gerrit responded with 429, waiting 30 s (attempt 1 of 4)'),
            ],
        ]);

        $steps = $run->steps(self::GERRIT_FULL, $this->now);

        self::assertSame(self::GERRIT_FULL, array_map(static fn (CommandRunStep $step): string => $step->name, $steps));
        self::assertSame([CommandRunStep::DONE, CommandRunStep::CURRENT, CommandRunStep::UPCOMING], array_map(static fn (CommandRunStep $step): string => $step->state, $steps));
        self::assertSame(240, $steps[0]->seconds);
        self::assertSame(30, $steps[1]->percent());
        self::assertSame('Gerrit responded with 429, waiting 30 s (attempt 1 of 4)', $steps[1]->waiting);
        self::assertNull($steps[2]->seconds);
    }

    public function testARunThatEndedEarlySkippedTheStepsItDidNotGetTo(): void
    {
        $run = $this->commandRun('-10 minutes', CommandRunStatusEnum::FAILED, more: [
            'steps' => [$this->step(GerritImport::STEP_FULL_LIST, '-10 minutes', '-9 minutes', 3000)],
        ]);

        $states = array_map(static fn (CommandRunStep $step): string => $step->state, $run->steps(self::GERRIT_FULL, $this->now));

        self::assertSame([CommandRunStep::STOPPED, CommandRunStep::SKIPPED, CommandRunStep::SKIPPED], $states);
    }

    public function testARunThatWentAnotherWayThanItsJobDeclaresShowsItsOwnSteps(): void
    {
        // forgeDelta into an empty index imports the latest issues instead of the changed ones
        $run = $this->commandRun('-2 minutes', CommandRunStatusEnum::WARNING, more: [
            'steps' => [$this->step(ForgeImport::STEP_LATEST, '-2 minutes', '-1 minute', 100)],
        ]);

        $steps = $run->steps([ForgeImport::STEP_DELTA], $this->now);

        self::assertSame([ForgeImport::STEP_LATEST], array_map(static fn (CommandRunStep $step): string => $step->name, $steps));
        self::assertSame(CommandRunStep::DONE, $steps[0]->state);
    }

    public function testARunFromBeforeTheStepsShowsItsLastPhase(): void
    {
        $run = $this->commandRun('-3 minutes', CommandRunStatusEnum::RUNNING, heartbeat: '-5 seconds', more: [
            'phase' => GerritImport::STEP_FULL_LIST,
            'phaseStartedAt' => $this->now->modify('-3 minutes'),
            'progressCurrent' => 31500,
        ]);

        $steps = $run->steps(self::GERRIT_FULL, $this->now);

        self::assertSame([CommandRunStep::CURRENT, CommandRunStep::UPCOMING, CommandRunStep::UPCOMING], array_map(static fn (CommandRunStep $step): string => $step->state, $steps));
        self::assertSame(31500, $steps[0]->current);
    }

    public function testARunWhoseStepsTheJobDoesNotDeclareShowsItsOwnOnly(): void
    {
        // A run of the release before the steps got their names
        $run = $this->commandRun('-10 minutes', CommandRunStatusEnum::RUNNING, heartbeat: '-5 seconds', more: [
            'phase' => 'Gerrit changes',
            'phaseStartedAt' => $this->now->modify('-6 minutes'),
            'progressCurrent' => 5751,
            'progressTotal' => 41547,
        ]);

        $steps = $run->steps(self::GERRIT_FULL, $this->now);

        self::assertSame(['Gerrit changes'], array_map(static fn (CommandRunStep $step): string => $step->name, $steps));
        self::assertSame(CommandRunStep::CURRENT, $steps[0]->state);
    }

    public function testAStepStillToComeIsNeverBeforeTheCurrentOne(): void
    {
        // The latest changes into an empty index, a step reviewDelta does not declare, after its listing
        $run = $this->commandRun('-2 minutes', CommandRunStatusEnum::RUNNING, heartbeat: '-5 seconds', more: [
            'steps' => [
                $this->step(GerritImport::STEP_DELTA_LIST, '-2 minutes', '-1 minute', 30),
                $this->step(GerritImport::STEP_LATEST, '-1 minute', null, 10, 100),
            ],
        ]);

        $steps = $run->steps([GerritImport::STEP_DELTA_LIST, GerritImport::STEP_DELTA_LOAD], $this->now);

        self::assertSame([GerritImport::STEP_DELTA_LIST, GerritImport::STEP_LATEST, GerritImport::STEP_DELTA_LOAD], array_map(static fn (CommandRunStep $step): string => $step->name, $steps));
        self::assertSame([CommandRunStep::DONE, CommandRunStep::CURRENT, CommandRunStep::UPCOMING], array_map(static fn (CommandRunStep $step): string => $step->state, $steps));
    }

    public function testBeforeItsFirstStepARunShowsAllStepsToCome(): void
    {
        $run = $this->commandRun('-5 seconds', CommandRunStatusEnum::RUNNING);

        $states = array_map(static fn (CommandRunStep $step): string => $step->state, $run->steps(self::GERRIT_FULL, $this->now));

        self::assertSame([CommandRunStep::UPCOMING, CommandRunStep::UPCOMING, CommandRunStep::UPCOMING], $states);
    }

    public function testTheWarningsBeyondTheLogAreCounted(): void
    {
        $run = $this->commandRun('-5 minutes', CommandRunStatusEnum::WARNING, more: [
            'warnings' => 302,
            'log' => [
                ['at' => '2026-11-19T15:55:00+01:00', 'level' => 'info', 'message' => 'Catching up'],
                ['at' => '2026-11-19T15:56:00+01:00', 'level' => 'warning', 'message' => 'Skipped Forge issue #2: Timeout', 'item' => ['type' => 'issue', 'id' => '2']],
                ['at' => '2026-11-19T15:57:00+01:00', 'level' => 'warning', 'message' => 'Skipped Forge issue #3: Timeout', 'item' => ['type' => 'issue', 'id' => '3']],
            ],
        ]);
        $before = $this->commandRun('-5 minutes', CommandRunStatusEnum::WARNING, more: ['warnings' => 7]);

        self::assertCount(3, $run->getLog());
        self::assertSame(300, $run->getWarningsNotLogged());
        // A run from before the log has its message
        self::assertSame([], $before->getLog());
        self::assertSame(0, $before->getWarningsNotLogged());
    }

    public function testTheMessageOfAFailedRunIsItsErrorThenItsWarnings(): void
    {
        $run = $this->commandRun('-5 minutes', CommandRunStatusEnum::FAILED, more: [
            'message' => "Elastica\\Exception\\ResponseException: no such index [review_english]\nForge issue #12: timeout",
            'warnings' => 1,
        ]);

        self::assertSame(['error' => 'no such index [review_english]', 'errorType' => 'ResponseException', 'reason' => null, 'warnings' => ['Forge issue #12: timeout'], 'moreWarnings' => 0], $run->getMessageParts($this->now));
        // Five warnings listed, the others counted
        $many = $this->commandRun('-5 minutes', CommandRunStatusEnum::FAILED, more: [
            'message' => "RuntimeException: Gerrit responded with 503\n#1\n#2\n#3\n#4\n#5\n... and 2 more warnings",
            'warnings' => 7,
        ]);
        self::assertSame('Gerrit responded with 503', $many->getMessageParts($this->now)['error']);
        self::assertSame('RuntimeException', $many->getMessageParts($this->now)['errorType']);
        self::assertSame(['#1', '#2', '#3', '#4', '#5'], $many->getMessageParts($this->now)['warnings']);
        self::assertSame(2, $many->getMessageParts($this->now)['moreWarnings']);
    }

    public function testAnErrorWithoutAnExceptionIsTheErrorAsItIs(): void
    {
        $run = $this->commandRun('-5 minutes', CommandRunStatusEnum::FAILED, more: ['message' => 'Gerrit responded with 503']);

        self::assertSame('Gerrit responded with 503', $run->getMessageParts($this->now)['error']);
        self::assertNull($run->getMessageParts($this->now)['errorType']);
    }

    public function testAFailedRunWithWarningsOnlyHasNoError(): void
    {
        // A non-zero exit code without an exception: the warnings are all of the message
        $run = $this->commandRun('-5 minutes', CommandRunStatusEnum::FAILED, more: ['message' => 'Forge issue #12: timeout', 'warnings' => 1]);

        self::assertNull($run->getMessageParts($this->now)['error']);
        self::assertSame(['Forge issue #12: timeout'], $run->getMessageParts($this->now)['warnings']);
    }

    public function testTheMessageOfAStoppedRunIsWhyItStopped(): void
    {
        $interrupted = $this->commandRun('-5 minutes', CommandRunStatusEnum::INTERRUPTED, more: ['message' => 'Interrupted: a deployment']);
        $cancelled = $this->commandRun('-5 minutes', CommandRunStatusEnum::CANCELLED, more: ['message' => "Cancelled by dev-admin\nForge issue #12: timeout", 'warnings' => 1]);

        self::assertSame('Interrupted: a deployment', $interrupted->getMessageParts($this->now)['reason']);
        self::assertNull($interrupted->getMessageParts($this->now)['error']);
        self::assertSame('Cancelled by dev-admin', $cancelled->getMessageParts($this->now)['reason']);
        self::assertSame(['Forge issue #12: timeout'], $cancelled->getMessageParts($this->now)['warnings']);
    }

    public function testTheMessageOfARunWithWarningsIsItsWarnings(): void
    {
        $run = $this->commandRun('-5 minutes', CommandRunStatusEnum::WARNING, more: ['message' => "Forge issue #12: timeout\nForge issue #13: timeout", 'warnings' => 2]);

        self::assertSame(['error' => null, 'errorType' => null, 'reason' => null, 'warnings' => ['Forge issue #12: timeout', 'Forge issue #13: timeout'], 'moreWarnings' => 0], $run->getMessageParts($this->now));
    }

    public function testARunningJobIsNotOverdue(): void
    {
        // reviewFull, queued with "Run now" for the first time, runs for hours: no success yet, but at work
        $trigger = new WeeklyTrigger('monday', '03:17');
        $overview = new CommandJobOverview('app:import:review --full', [
            $this->commandRun('-1 minute', CommandRunStatusEnum::RUNNING, heartbeat: '-10 seconds'),
            $this->commandRun('-5 minutes', CommandRunStatusEnum::LOCKED),
        ], $this->now, $trigger);

        self::assertFalse($overview->overdue);
        self::assertNull($overview->lastSuccess);
    }

    public function testAJobWithoutASuccessYetIsCountedFromItsFirstRun(): void
    {
        // Only skipped for the lock so far, the first run this Thursday: the planned runs are to come
        $trigger = new WeeklyTrigger('monday', '03:17');
        $overview = new CommandJobOverview('app:import:review --full', [$this->commandRun('-5 minutes', CommandRunStatusEnum::LOCKED)], $this->now, $trigger);

        self::assertFalse($overview->overdue);
        // Failing since its first run, for longer than three planned runs: overdue
        $deltas = $this->overview(['-2 minutes', '-7 minutes', '-12 minutes', '-17 minutes', '-22 minutes', '-27 minutes'], array_fill(0, 6, CommandRunStatusEnum::FAILED));
        self::assertTrue($deltas->overdue);
    }

    public function testAJobWaitingForAnotherWithItsLockIsNotOverdue(): void
    {
        // forgeDelta has not run for an hour: forgeFull holds the lock of Forge
        $runs = [$this->commandRun('-1 hour', CommandRunStatusEnum::SUCCESS), $this->commandRun('-1 hour -5 minutes', CommandRunStatusEnum::SUCCESS)];
        $trigger = new PeriodicalTrigger('5 minutes', new \DateTimeImmutable('2026-11-19 00:00'));

        self::assertTrue(new CommandJobOverview('app:import:forge', $runs, $this->now, $trigger)->overdue);
        self::assertFalse(new CommandJobOverview('app:import:forge', $runs, $this->now, $trigger, blockedBy: 'app:import:forge --full')->overdue);
    }

    public function testInterruptedAndCancelledRunsAreNoFailures(): void
    {
        $overview = $this->overview(['-2 minutes', '-7 minutes'], [CommandRunStatusEnum::INTERRUPTED, CommandRunStatusEnum::CANCELLED]);

        self::assertSame(0, $overview->failures);
        self::assertFalse($overview->overdue);
        self::assertFalse($overview->needsAttention($this->now));
    }

    public function testAWeeklyJobIsOverdueOnceItsRunIsMissing(): void
    {
        $trigger = new WeeklyTrigger('monday', '03:17');
        // 2026-11-19 is a Thursday: the run of Monday is there, the next one is to come
        $fine = new CommandJobOverview('app:import:review --full', [$this->commandRun('2026-11-16 03:17', CommandRunStatusEnum::SUCCESS)], $this->now, $trigger);
        // The run of Monday is missing
        $missing = new CommandJobOverview('app:import:review --full', [$this->commandRun('2026-11-09 03:17', CommandRunStatusEnum::SUCCESS)], $this->now, $trigger);

        self::assertFalse($fine->overdue);
        self::assertSame('Mon 23 03:17', $fine->nextRun?->format('D d H:i'));
        self::assertTrue($missing->overdue);
    }

    /**
     * @param list<string>               $starts   newest first
     * @param list<CommandRunStatusEnum> $statuses
     */
    private function overview(array $starts, array $statuses = []): CommandJobOverview
    {
        $runs = [];
        foreach ($starts as $position => $start) {
            $runs[] = $this->commandRun($start, $statuses[$position] ?? CommandRunStatusEnum::SUCCESS);
        }

        return new CommandJobOverview('app:import:review', $runs, $this->now, new PeriodicalTrigger('5 minutes', new \DateTimeImmutable('2026-11-19 00:00')));
    }

    /**
     * @param array<string, mixed> $more further properties of the run
     */
    /**
     * A step as the recorder writes it.
     *
     * @return array{name: string, startedAt: string, endedAt: ?string, current: ?int, total: ?int, waiting: ?string}
     */
    private function step(string $name, string $start, ?string $end, ?int $current, ?int $total = null, ?string $waiting = null): array
    {
        return [
            'name' => $name,
            'startedAt' => $this->now->modify($start)->format(\DATE_ATOM),
            'endedAt' => null === $end ? null : $this->now->modify($end)->format(\DATE_ATOM),
            'current' => $current,
            'total' => $total,
            'waiting' => $waiting,
        ];
    }

    /**
     * @param array<string, mixed> $more
     */
    private function commandRun(string $start, CommandRunStatusEnum $status, ?string $heartbeat = null, array $more = []): CommandRun
    {
        $run = new \ReflectionClass(CommandRun::class)->newInstanceWithoutConstructor();
        $values = [
            'job' => 'app:import:review',
            'status' => $status,
            'startedAt' => $this->now->modify($start),
            'heartbeatAt' => $this->now->modify($heartbeat ?? $start),
            'finishedAt' => CommandRunStatusEnum::RUNNING === $status ? null : $this->now->modify($start),
        ] + $more;
        foreach ($values as $property => $value) {
            new \ReflectionProperty(CommandRun::class, $property)->setValue($run, $value);
        }

        return $run;
    }
}
