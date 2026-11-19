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
use App\Entity\CommandRun;
use App\Enum\CommandRunStatusEnum;
use App\Subscriber\CommandRunSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class CommandRunTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-19 16:00:00');
    }

    public function testJobNamesTheOptionsThatDifferFromTheirDefaults(): void
    {
        // The options of app:import
        $definition = new InputDefinition([
            new InputOption('mode', null, InputOption::VALUE_REQUIRED),
            new InputOption('startAt', null, InputOption::VALUE_OPTIONAL, '', 0),
            new InputOption('organization', null, InputOption::VALUE_OPTIONAL, '', 'TYPO3-Documentation'),
            new InputOption('repository', null, InputOption::VALUE_OPTIONAL),
            new InputOption('verbose', 'v', InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--mode' => 'reviewDelta', '--startAt' => '0', '--organization' => 'TYPO3-Documentation', '--verbose' => true], $definition);
        self::assertSame('app:import --mode=reviewDelta', CommandRunSubscriber::job('app:import', $input, $definition, ['verbose']));

        $input = new ArrayInput(['--mode' => 'githubDelta', '--organization' => 'TYPO3', '--repository' => 'TYPO3.Icons'], $definition);
        self::assertSame('app:import --mode=githubDelta --organization=TYPO3 --repository=TYPO3.Icons', CommandRunSubscriber::job('app:import', $input, $definition));
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

    public function testAJobRunningAtItsUsualIntervalIsNotOverdue(): void
    {
        $overview = $this->overview(['-2 minutes', '-12 minutes', '-22 minutes', '-32 minutes']);

        self::assertSame(600, $overview->interval);
        self::assertFalse($overview->overdue);
        self::assertSame(0, $overview->failures);
    }

    public function testAJobCronNoLongerStartsIsOverdue(): void
    {
        $overview = $this->overview(['-3 hours', '-3 hours -10 minutes', '-3 hours -20 minutes', '-3 hours -30 minutes']);

        self::assertTrue($overview->overdue);
    }

    public function testAJobThatKeepsFailingIsOverdue(): void
    {
        $overview = $this->overview(
            ['-2 minutes', '-12 minutes', '-22 minutes', '-32 minutes', '-42 minutes', '-52 minutes'],
            [CommandRunStatusEnum::FAILED, CommandRunStatusEnum::FAILED, CommandRunStatusEnum::FAILED, CommandRunStatusEnum::FAILED, CommandRunStatusEnum::FAILED, CommandRunStatusEnum::SUCCESS],
        );

        self::assertSame(5, $overview->failures);
        self::assertTrue($overview->overdue);
        self::assertSame(CommandRunStatusEnum::SUCCESS, $overview->lastSuccess?->getStatus($this->now));
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

    public function testAJobWithFewRunsHasNoIntervalAndIsNeverOverdue(): void
    {
        $overview = $this->overview(['-10 days', '-11 days']);

        self::assertNull($overview->interval);
        self::assertFalse($overview->overdue);
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

        return new CommandJobOverview('app:import --mode=reviewDelta', $runs, $this->now);
    }

    private function commandRun(string $start, CommandRunStatusEnum $status, ?string $heartbeat = null): CommandRun
    {
        $run = new \ReflectionClass(CommandRun::class)->newInstanceWithoutConstructor();
        $values = [
            'job' => 'app:import --mode=reviewDelta',
            'status' => $status,
            'startedAt' => $this->now->modify($start),
            'heartbeatAt' => $this->now->modify($heartbeat ?? $start),
            'finishedAt' => CommandRunStatusEnum::RUNNING === $status ? null : $this->now->modify($start),
        ];
        foreach ($values as $property => $value) {
            new \ReflectionProperty(CommandRun::class, $property)->setValue($run, $value);
        }

        return $run;
    }
}
