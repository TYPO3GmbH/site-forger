<?php

namespace App\Repository;

use App\Entity\SprintBoard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method SprintBoard|null find($id, $lockMode = null, $lockVersion = null)
 * @method SprintBoard|null findOneBy(array $criteria, array $orderBy = null)
 * @method SprintBoard[]    findAll()
 * @method SprintBoard[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SprintBoardRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, SprintBoard::class);
    }

    // /**
    //  * @return SprintBoard[] Returns an array of SprintBoard objects
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
    public function findOneBySomeField($value): ?SprintBoard
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
