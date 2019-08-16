<?php

namespace App\Repository;

use App\Entity\EmploymentTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method EmploymentTime|null find($id, $lockMode = null, $lockVersion = null)
 * @method EmploymentTime|null findOneBy(array $criteria, array $orderBy = null)
 * @method EmploymentTime[]    findAll()
 * @method EmploymentTime[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EmploymentTimeRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, EmploymentTime::class);
    }
}
