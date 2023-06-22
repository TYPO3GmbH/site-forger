<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\BackportBlacklistEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BackportBlacklistEntry|null find($id, $lockMode = null, $lockVersion = null)
 * @method BackportBlacklistEntry|null findOneBy(array $criteria, array $orderBy = null)
 * @method BackportBlacklistEntry[]    findAll()
 * @method BackportBlacklistEntry[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BackportBlacklistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackportBlacklistEntry::class);
    }
}
