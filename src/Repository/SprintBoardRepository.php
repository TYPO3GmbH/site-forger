<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\SprintBoard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method SprintBoard|null find($id, $lockMode = null, $lockVersion = null)
 * @method SprintBoard|null findOneBy(array $criteria, array $orderBy = null)
 * @method SprintBoard[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SprintBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SprintBoard::class);
    }

    public function findAll(): array
    {
        return $this->findBy([], ['sorting' => 'ASC', 'name' => 'ASC']);
    }
}
