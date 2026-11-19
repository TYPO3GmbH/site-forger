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
     * @param list<CommandRunStatusEnum>|null $statuses
     *
     * @return list<CommandRun> newest first
     */
    public function findLatest(int $limit, ?string $job = null, ?array $statuses = null): array
    {
        $query = $this->createQueryBuilder('run')
            ->orderBy('run.startedAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->setMaxResults($limit);
        if (null !== $job) {
            $query->andWhere('run.job = :job')->setParameter('job', $job);
        }
        if (null !== $statuses) {
            $query->andWhere('run.status IN (:statuses)')->setParameter('statuses', array_map(static fn (CommandRunStatusEnum $status): string => $status->value, $statuses));
        }

        return $query->getQuery()->getResult();
    }

    /**
     * The runs of the last days, grouped by job, newest first.
     *
     * @return array<string, list<CommandRun>>
     */
    public function findRecentByJob(\DateTimeImmutable $since): array
    {
        $runs = $this->createQueryBuilder('run')
            ->where('run.startedAt >= :since')
            ->setParameter('since', $since)
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
}
