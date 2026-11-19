<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Enum\CommandRunStatusEnum;
use App\Repository\CommandRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run of a console command of the application, mostly the imports started by cron.
 *
 * Written by App\Service\CommandRunRecorder with plain DBAL statements, so a long import
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

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * The command name and its options, e.g. "app:import --mode=reviewDelta", runs of the same job share it.
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

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $progressCurrent = null;

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
