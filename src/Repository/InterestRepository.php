<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\Interest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The interests of the users, see Interest.
 *
 * @extends ServiceEntityRepository<Interest>
 */
class InterestRepository extends ServiceEntityRepository
{
    /**
     * The interests one user may have.
     */
    public const int LIMIT = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Interest::class);
    }

    /**
     * @return list<Interest> in the order they were added
     */
    public function ofOwner(string $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function ofOwnerById(string $owner, int $id): ?Interest
    {
        return $this->findOneBy(['owner' => $owner, 'id' => $id]);
    }

    public function save(Interest $interest): void
    {
        $this->getEntityManager()->persist($interest);
        $this->getEntityManager()->flush();
    }

    public function remove(Interest $interest): void
    {
        $this->getEntityManager()->remove($interest);
        $this->getEntityManager()->flush();
    }
}
