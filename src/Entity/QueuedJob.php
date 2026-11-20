<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\QueuedJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A job of the schedule an admin asked to run now, on the command runs page. The worker of the schedule starts
 * it within a minute, see App\Scheduler\StartQueuedJobsHandler, and removes it from the queue.
 */
#[ORM\Entity(repositoryClass: QueuedJobRepository::class)]
#[ORM\Table(name: 'job_queue')]
#[ORM\UniqueConstraint(name: 'job_queue_job', columns: ['job'])]
class QueuedJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        /** The job as its runs are recorded, like "app:import --mode=forgeDelta" */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $job,
        /** Who or what queued it, as the page shows it: "Run now by dev-admin", "interrupted, a deployment" */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $queuedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $queuedAt,
        /** To import everything again, also what did not change: started with --force, see JobSchedule::JOBS */
        #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
        private bool $forced = false,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): string
    {
        return $this->job;
    }

    public function getQueuedBy(): string
    {
        return $this->queuedBy;
    }

    public function getQueuedAt(): \DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function isForced(): bool
    {
        return $this->forced;
    }
}
