<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\EmploymentTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Collection;
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

    /**
     * @param string $username
     * @param array $orderBy
     * @return EmploymentTime[]
     */
    public function findByUsername(string $username, array $orderBy = ['employedFrom' => 'desc']): array
    {
        return $this->findBy(['account' => $username], $orderBy);
    }
}
