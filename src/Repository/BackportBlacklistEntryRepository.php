<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\BackportBlacklistEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method BackportBlacklistEntry|null find($id, $lockMode = null, $lockVersion = null)
 * @method BackportBlacklistEntry|null findOneBy(array $criteria, array $orderBy = null)
 * @method BackportBlacklistEntry[]    findAll()
 * @method BackportBlacklistEntry[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BackportBlacklistEntryRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, BackportBlacklistEntry::class);
    }

    // /**
    //  * @return BackportBlacklistEntry[] Returns an array of BackportBlacklistEntry objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('b.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?BackportBlacklistEntry
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
