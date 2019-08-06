<?php

namespace App\Repository;

use App\Entity\SprintMenuItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method SprintMenuItem|null find($id, $lockMode = null, $lockVersion = null)
 * @method SprintMenuItem|null findOneBy(array $criteria, array $orderBy = null)
 * @method SprintMenuItem[]    findAll()
 * @method SprintMenuItem[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SprintMenuItemRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, SprintMenuItem::class);
    }

    // /**
    //  * @return SprintMenuItem[] Returns an array of SprintMenuItem objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?SprintMenuItem
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
