<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\CommandRun;
use App\Enum\CommandRunStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommandRun>
 */
class CommandRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommandRun::class);
    }

    /**
     * @param list<string>                    $jobs     the jobs of the runs, as recorded
     * @param list<CommandRunStatusEnum>|null $statuses
     *
     * @return list<CommandRun> newest first
     */
    public function findLatest(int $limit, array $jobs, ?array $statuses = null): array
    {
        $query = $this->createQueryBuilder('run')
            ->where('run.job IN (:jobs)')
            ->setParameter('jobs', $jobs)
            ->orderBy('run.startedAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->setMaxResults($limit);
        if (null !== $statuses) {
            $query->andWhere('run.status IN (:statuses)')->setParameter('statuses', array_map(static fn (CommandRunStatusEnum $status): string => $status->value, $statuses));
        }

        return $query->getQuery()->getResult();
    }

    /**
     * The runs of the last days of some jobs, grouped by job, newest first.
     *
     * @param list<string> $jobs the jobs, as recorded
     *
     * @return array<string, list<CommandRun>>
     */
    public function findRecentByJob(\DateTimeImmutable $since, array $jobs): array
    {
        $runs = $this->createQueryBuilder('run')
            ->where('run.startedAt >= :since')
            ->andWhere('run.job IN (:jobs)')
            ->setParameter('since', $since)
            ->setParameter('jobs', $jobs)
            ->orderBy('run.job', 'ASC')
            ->addOrderBy('run.startedAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->getQuery()
            ->getResult();

        $byJob = [];
        foreach ($runs as $run) {
            $byJob[$run->getJob()][] = $run;
        }

        return $byJob;
    }

    /**
     * The runs of some jobs that are running now: those with a heartbeat, see CommandRun::getStatus().
     *
     * @param list<string> $jobs the jobs, as recorded
     *
     * @return list<CommandRun>
     */
    public function findRunning(array $jobs, \DateTimeImmutable $now): array
    {
        if ([] === $jobs) {
            return [];
        }
        $runs = $this->createQueryBuilder('run')
            ->where('run.status = :running')
            ->andWhere('run.job IN (:jobs)')
            ->setParameter('running', CommandRunStatusEnum::RUNNING->value)
            ->setParameter('jobs', $jobs)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($runs, static fn (CommandRun $run): bool => CommandRunStatusEnum::RUNNING === $run->getStatus($now)));
    }

    /**
     * Asks a running run to stop, at its next heartbeat, see App\Service\CommandRunRecorder.
     *
     * @return bool whether it was asked now: not if it ended or was asked already
     */
    public function requestStop(int $id, CommandRunStatusEnum $as, string $reason): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE command_run SET stop_status = ?, stop_reason = ? WHERE id = ? AND status = ? AND stop_status IS NULL',
            [$as->value, mb_substr($reason, 0, 255), $id, CommandRunStatusEnum::RUNNING->value],
        );
    }

    /**
     * Whether a run is still running, read again from the database, for the wait for runs to stop.
     */
    public function isStillRunning(int $id, \DateTimeImmutable $now): bool
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT status, heartbeat_at FROM command_run WHERE id = ?', [$id]);

        return is_array($row)
            && CommandRunStatusEnum::RUNNING->value === $row['status']
            && $now->getTimestamp() - new \DateTimeImmutable((string) $row['heartbeat_at'])->getTimestamp() <= CommandRun::HEARTBEAT_TIMEOUT;
    }
}
