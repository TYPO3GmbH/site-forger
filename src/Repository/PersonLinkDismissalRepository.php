<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\PersonLinkDismissal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The suggestions the admins turned down, see PersonLinkDismissal.
 *
 * @extends ServiceEntityRepository<PersonLinkDismissal>
 */
class PersonLinkDismissalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonLinkDismissal::class);
    }

    public function dismiss(string $account, string $person, string $dismissedBy, \DateTimeImmutable $now): void
    {
        try {
            $this->getEntityManager()->getConnection()->insert('person_link_dismissal', [
                'account' => $account,
                'person' => $person,
                'dismissed_by' => $dismissedBy,
                'dismissed_at' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Turned down already
        }
    }

    /**
     * @return array<string, true> by "<account> <person>"
     */
    public function pairs(): array
    {
        $pairs = [];
        foreach ($this->findAll() as $dismissal) {
            $pairs[$dismissal->getAccount() . ' ' . $dismissal->getPerson()] = true;
        }

        return $pairs;
    }
}
