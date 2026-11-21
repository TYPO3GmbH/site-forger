<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\CommandRun\CommandRunRecorder;
use App\DTO\CommandRunStep;
use App\Enum\CommandRunStatusEnum;
use App\Repository\CommandRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run of a console command of the application, mostly the imports started by cron.
 *
 * Written by App\CommandRun\CommandRunRecorder with plain DBAL statements, so a long import
 * can update its run after the database connection timed out. Read through the ORM.
 */
#[ORM\Entity(repositoryClass: CommandRunRepository::class, readOnly: true)]
#[ORM\Index(name: 'command_run_job', columns: ['job', 'started_at'])]
#[ORM\Index(name: 'command_run_started', columns: ['started_at'])]
class CommandRun
{
    /**
     * Without a heartbeat for this long a run that did not end is considered aborted (killed, out of memory).
     */
    public const int HEARTBEAT_TIMEOUT = 900;

    /**
     * A phase needs to run this long before its speed is shown.
     */
    private const int MIN_SECONDS_FOR_SPEED = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * The command name and its options, e.g. "app:import:review --full", runs of the same job share it.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $job = '';

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CommandRunStatusEnum::class)]
    private CommandRunStatusEnum $status = CommandRunStatusEnum::RUNNING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $heartbeatAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $exitCode = null;

    /**
     * What the run is doing, e.g. "Gerrit changes", reported by the command while it runs.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $phase = null;

    /**
     * Asked to stop, by a pause or an admin: the run stops at its next heartbeat with this status, see
     * App\CommandRun\CommandRunRecorder, and the reason as its message.
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: CommandRunStatusEnum::class)]
    private ?CommandRunStatusEnum $stopStatus = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $stopReason = null;

    /**
     * When the phase started, for the speed and the time left of the phase.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $phaseStartedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $progressCurrent = null;

    /**
     * The steps so far as the recorder writes them, see App\CommandRun\CommandRunRecorder::stepsJson(); null for the
     * runs from before the steps were recorded.
     *
     * @var ?list<array{name: string, startedAt: string, endedAt: ?string, current: ?int, total: ?int, waiting: ?string}>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $steps = null;

    /**
     * What the run noted, waited for, skipped and failed with, in the order it happened, each with its time, level,
     * step, item and exception, see App\CommandRun\CommandRunRecorder::log(); null for the runs from before, their
     * message has the first warnings.
     *
     * @var ?list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $log = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $progressTotal = null;

    /**
     * Documents written to Elasticsearch.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $documents = 0;

    /**
     * Items the command skipped because of an error, the run went on.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $warnings = 0;

    /**
     * The error that ended the run, or the first warnings.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $host = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $pid = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): string
    {
        return $this->job;
    }

    /**
     * The stored status, except for a run that stopped sending heartbeats without an end: it was aborted.
     */
    public function getStatus(?\DateTimeImmutable $now = null): CommandRunStatusEnum
    {
        if (CommandRunStatusEnum::RUNNING === $this->status
            && ($now ?? new \DateTimeImmutable())->getTimestamp() - $this->heartbeatAt->getTimestamp() > self::HEARTBEAT_TIMEOUT
        ) {
            return CommandRunStatusEnum::ABORTED;
        }

        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getHeartbeatAt(): \DateTimeImmutable
    {
        return $this->heartbeatAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * Seconds the run took, or has been running up to its last heartbeat.
     */
    public function getDuration(): int
    {
        return ($this->finishedAt ?? $this->heartbeatAt)->getTimestamp() - $this->startedAt->getTimestamp();
    }

    public function getPhase(): ?string
    {
        return $this->phase;
    }

    /**
     * Whether the run was asked to stop and has not yet.
     */
    public function isStopping(): bool
    {
        return null !== $this->stopStatus && CommandRunStatusEnum::RUNNING === $this->status;
    }

    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    public function getPhaseStartedAt(): ?\DateTimeImmutable
    {
        return $this->phaseStartedAt;
    }

    /**
     * Items per minute in the phase, as of the last heartbeat; null for the first seconds of a phase, which say
     * little yet.
     */
    public function getProgressPerMinute(): ?float
    {
        if (null === $this->phaseStartedAt || null === $this->progressCurrent || $this->progressCurrent <= 0) {
            return null;
        }
        $seconds = $this->heartbeatAt->getTimestamp() - $this->phaseStartedAt->getTimestamp();

        return $seconds < self::MIN_SECONDS_FOR_SPEED ? null : $this->progressCurrent * 60 / $seconds;
    }

    /**
     * When the phase will be done at its speed so far, null without a total or a speed.
     */
    public function getPhaseEndsAt(): ?\DateTimeImmutable
    {
        $perMinute = $this->getProgressPerMinute();
        if (null === $perMinute || null === $this->progressTotal || null === $this->progressCurrent) {
            return null;
        }
        $left = max(0, $this->progressTotal - $this->progressCurrent);

        return $this->heartbeatAt->modify('+' . (int) ceil($left * 60 / $perMinute) . ' seconds');
    }

    /**
     * The steps of the run in their order: those it went through, the one it is in or ended in, then those declared
     * for its job it did not get to, each after the declared one before it, those still to come after the current
     * one. A step the job does not declare shows where the run went through it; a run with none of the declared
     * steps (the latest issues into an empty index, a run from before the names changed) shows its own only.
     *
     * @param list<string> $declared the steps of the job, see App\Scheduler\JobSchedule::JOBS
     *
     * @return list<CommandRunStep>
     */
    public function steps(array $declared = [], ?\DateTimeImmutable $now = null): array
    {
        $status = $this->getStatus($now);
        $running = CommandRunStatusEnum::RUNNING === $status;
        $recorded = $this->steps ?? [];
        // A run from before the steps were recorded: its last phase
        if ([] === $recorded && null !== $this->phase) {
            $recorded = [[
                'name' => $this->phase,
                'startedAt' => ($this->phaseStartedAt ?? $this->startedAt)->format(\DATE_ATOM),
                'endedAt' => $running ? null : ($this->finishedAt ?? $this->heartbeatAt)->format(\DATE_ATOM),
                'current' => $this->progressCurrent,
                'total' => $this->progressTotal,
                'waiting' => null,
            ]];
        }

        $steps = [];
        $last = array_key_last($recorded);
        foreach ($recorded as $position => $step) {
            $start = new \DateTimeImmutable($step['startedAt']);
            $end = null === $step['endedAt'] ? $this->heartbeatAt : new \DateTimeImmutable($step['endedAt']);
            $state = match (true) {
                $position !== $last => CommandRunStep::DONE,
                $running => CommandRunStep::CURRENT,
                $status->isSuccessful() => CommandRunStep::DONE,
                default => CommandRunStep::STOPPED,
            };
            $steps[$step['name']] = new CommandRunStep(
                $step['name'],
                $state,
                $step['current'],
                $step['total'],
                max(0, $end->getTimestamp() - $start->getTimestamp()),
                CommandRunStep::CURRENT === $state ? ($step['waiting'] ?? null) : null,
            );
        }

        // Steps of which the job declares none, of a run from before the names of its steps changed for example:
        // which of the declared ones it went through is not known, so it shows its own only
        if ([] !== $steps && [] === array_intersect($declared, array_keys($steps))) {
            return array_values($steps);
        }

        // The declared steps it did not get to, each after the one declared before it. One the run went past is
        // skipped, the others are still to come while it runs: after all it went through.
        $names = array_keys($steps);
        $recordedCount = count($names);
        $after = null;
        foreach ($declared as $index => $name) {
            if (!isset($steps[$name])) {
                $passed = [] !== array_intersect(array_slice($declared, $index + 1), array_keys($steps));
                $upcoming = $running && !$passed;
                $position = null === $after ? 0 : (int) array_search($after, $names, true) + 1;
                if ($upcoming) {
                    $position = max($position, $recordedCount);
                }
                array_splice($names, $position, 0, [$name]);
                $steps[$name] = new CommandRunStep($name, $upcoming ? CommandRunStep::UPCOMING : CommandRunStep::SKIPPED);
            }
            $after = $name;
        }

        return array_map(static fn (string $name): CommandRunStep => $steps[$name], $names);
    }

    public function getProgressCurrent(): ?int
    {
        return $this->progressCurrent;
    }

    public function getProgressTotal(): ?int
    {
        return $this->progressTotal;
    }

    /**
     * 0 to 100, null if the command reports no total.
     */
    public function getProgressPercent(): ?int
    {
        if (null === $this->progressCurrent || null === $this->progressTotal || $this->progressTotal <= 0) {
            return null;
        }

        return (int) min(100, floor($this->progressCurrent * 100 / $this->progressTotal));
    }

    /**
     * The message split as the recorder wrote it, see App\CommandRun\CommandRunRecorder::message(): the error of a
     * failed run (its exception in short, "ResponseException", and its text) or the reason a run was stopped,
     * then the warnings, and how many more there were.
     *
     * @return array{error: ?string, errorType: ?string, reason: ?string, warnings: list<string>, moreWarnings: int}
     */
    public function getMessageParts(?\DateTimeImmutable $now = null): array
    {
        $lines = null === $this->message || '' === $this->message ? [] : explode("\n", $this->message);
        $parts = ['error' => null, 'errorType' => null, 'reason' => null, 'warnings' => [], 'moreWarnings' => 0];
        $status = $this->getStatus($now);
        // The recorder lists the first warnings, and a line for the others
        $warningLines = min($this->warnings, CommandRunRecorder::MAX_WARNING_MESSAGES) + ($this->warnings > CommandRunRecorder::MAX_WARNING_MESSAGES ? 1 : 0);
        if ([] !== $lines && CommandRunStatusEnum::FAILED === $status && count($lines) > $warningLines) {
            $error = (string) array_shift($lines);
            if (preg_match('/^(?<class>[A-Za-z_][\w\\\\]*(?:Exception|Error)): (?<text>.*)$/s', $error, $match)) {
                $parts['errorType'] = substr((string) strrchr('\\' . $match['class'], '\\'), 1);
                $error = $match['text'];
            }
            $parts['error'] = $error;
        } elseif ([] !== $lines && in_array($status, [CommandRunStatusEnum::INTERRUPTED, CommandRunStatusEnum::CANCELLED], true)) {
            $parts['reason'] = array_shift($lines);
        }
        foreach ($lines as $line) {
            if (preg_match('/^\.\.\. and (\d+) more warnings$/', $line, $match)) {
                $parts['moreWarnings'] = (int) $match[1];
            } else {
                $parts['warnings'][] = $line;
            }
        }

        return $parts;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getDocuments(): int
    {
        return $this->documents;
    }

    public function getWarnings(): int
    {
        return $this->warnings;
    }

    /**
     * The log of the run, see $log.
     *
     * @return list<array<string, mixed>>
     */
    public function getLog(): array
    {
        return $this->log ?? [];
    }

    /**
     * Whether the log has notes only: the run has no warning or error to tell. The list of runs folds such a log
     * behind a button.
     */
    public function hasNotesOnly(): bool
    {
        $log = $this->getLog();
        $notNotes = array_filter($log, static fn (array $entry): bool => 'info' !== ($entry['level'] ?? null));

        return [] !== $log && [] === $notNotes && 0 === $this->getWarningsNotLogged();
    }

    /**
     * The warnings beyond those in the log, see CommandRunRecorder::MAX_LOG_ENTRIES.
     */
    public function getWarningsNotLogged(): int
    {
        $logged = count(array_filter($this->getLog(), static fn (array $entry): bool => 'warning' === ($entry['level'] ?? null)));

        return null === $this->log ? 0 : max(0, $this->warnings - $logged);
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPid(): int
    {
        return $this->pid;
    }
}
