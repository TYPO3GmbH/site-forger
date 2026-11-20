<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\JobPauseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pause of a job of the schedule, or of all of them (ALL): while it lasts the job does not start, its planned
 * starts wait in the queue, see App\Scheduler\JobControl.
 */
#[ORM\Entity(repositoryClass: JobPauseRepository::class)]
#[ORM\Table(name: 'job_pause')]
#[ORM\UniqueConstraint(name: 'job_pause_job', columns: ['job'])]
class JobPause
{
    public const string ALL = '*';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        /** The job as its runs are recorded, or ALL */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $job,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $pausedBy,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $reason,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $pausedAt,
    ) {
    }

    public function getJob(): string
    {
        return $this->job;
    }

    public function isAll(): bool
    {
        return self::ALL === $this->job;
    }

    public function getPausedBy(): string
    {
        return $this->pausedBy;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getPausedAt(): \DateTimeImmutable
    {
        return $this->pausedAt;
    }
}
