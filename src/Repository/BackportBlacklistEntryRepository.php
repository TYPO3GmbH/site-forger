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
 * @extends ServiceEntityRepository<BackportBlacklistEntry>
 */
class BackportBlacklistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackportBlacklistEntry::class);
    }

    /**
     * @return list<string> the Change-Ids of all excluded changes
     */
    public function changeIds(): array
    {
        return array_map(
            static fn (BackportBlacklistEntry $entry): string => (string) $entry->getChangeId(),
            $this->findAll(),
        );
    }
}
