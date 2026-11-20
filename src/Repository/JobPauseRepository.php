<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\JobPause;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobPause>
 */
class JobPauseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobPause::class);
    }

    /**
     * Pauses a job, or all of them: a pause that exists already stays as it is.
     *
     * @return bool whether it was paused now
     */
    public function pause(string $job, string $pausedBy, string $reason, \DateTimeImmutable $now): bool
    {
        try {
            $this->getEntityManager()->getConnection()->insert('job_pause', [
                'job' => $job,
                'paused_by' => mb_substr($pausedBy, 0, 255),
                'reason' => mb_substr($reason, 0, 255),
                'paused_at' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Ends the pause of a job, or the pause of all jobs: the pauses of single jobs stay.
     */
    public function resume(string $job): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->delete('job_pause', ['job' => $job]);
    }

    /**
     * @return array<string, JobPause> by job, JobPause::ALL for the pause of all jobs
     */
    public function findPauses(): array
    {
        $pauses = [];
        foreach ($this->findBy([], ['pausedAt' => 'ASC']) as $pause) {
            $pauses[$pause->getJob()] = $pause;
        }

        return $pauses;
    }
}
