<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\SprintBoardCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method SprintBoardCategory|null find($id, $lockMode = null, $lockVersion = null)
 * @method SprintBoardCategory|null findOneBy(array $criteria, array $orderBy = null)
 * @method SprintBoardCategory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SprintBoardCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SprintBoardCategory::class);
    }

    public function findAll(): array
    {
        return $this->findBy([], ['sorting' => 'ASC', 'name' => 'ASC']);
    }
}
