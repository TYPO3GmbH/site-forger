<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\QueuedJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QueuedJob>
 */
class QueuedJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QueuedJob::class);
    }

    /**
     * Queues a job, once: a job in the queue already stays as it is.
     *
     * @param bool $forced to import everything again, see QueuedJob::isForced()
     *
     * @return bool whether it was queued now
     */
    public function queue(string $job, string $queuedBy, \DateTimeImmutable $now, bool $forced = false): bool
    {
        try {
            $this->getEntityManager()->getConnection()->insert('job_queue', [
                'job' => $job,
                'queued_by' => mb_substr($queuedBy, 0, 255),
                'queued_at' => $now->format('Y-m-d H:i:s'),
                'forced' => $forced ? 1 : 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * @return list<QueuedJob> the oldest first
     */
    public function findQueued(): array
    {
        return $this->findBy([], ['queuedAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Removes a job from the queue, as it starts anyway.
     */
    public function dequeue(string $job): void
    {
        $this->getEntityManager()->getConnection()->delete('job_queue', ['job' => $job]);
    }

    /**
     * Takes a job from the queue: true for the one worker that removed it, should two of them try.
     */
    public function claim(QueuedJob $job): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->delete('job_queue', ['id' => $job->getId()]);
    }
}
