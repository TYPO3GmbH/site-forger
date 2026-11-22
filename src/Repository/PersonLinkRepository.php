<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\PersonLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The accounts linked to a person by an admin, see PersonLink.
 *
 * @extends ServiceEntityRepository<PersonLink>
 */
class PersonLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonLink::class);
    }

    /**
     * @return array<string, string> the username of the person by the key of each account linked
     */
    public function persons(): array
    {
        $persons = [];
        foreach ($this->findAll() as $link) {
            $persons[$link->getAccount()] = $link->getPerson();
        }

        return $persons;
    }

    /**
     * @return list<PersonLink> all accounts linked, the latest first
     */
    public function latest(): array
    {
        return $this->findBy([], ['linkedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return list<PersonLink> the accounts linked to the person, the latest first
     */
    public function ofPerson(string $person): array
    {
        return $this->findBy(['person' => $person], ['linkedAt' => 'DESC']);
    }

    /**
     * Links the account to the person, or to another person than before. The accounts linked to the account move
     * along: a link always leads to a person, never to another link.
     */
    public function link(string $account, string $person, string $linkedBy, \DateTimeImmutable $now): void
    {
        $manager = $this->getEntityManager();
        foreach ($this->findBy(['account' => $account]) as $link) {
            $manager->remove($link);
        }
        foreach ($this->findBy(['person' => $account]) as $link) {
            $manager->remove($link);
            $manager->flush();
            $manager->persist(new PersonLink($link->getAccount(), $person, $linkedBy, $now));
        }
        $manager->flush();
        $manager->persist(new PersonLink($account, $person, $linkedBy, $now));
        $manager->flush();
    }

    public function unlink(string $account): void
    {
        $manager = $this->getEntityManager();
        foreach ($this->findBy(['account' => $account]) as $link) {
            $manager->remove($link);
        }
        $manager->flush();
    }
}
