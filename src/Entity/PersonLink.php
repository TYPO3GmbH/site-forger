<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\PersonLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An account that belongs to the person of a TYPO3 username, as an admin linked it: an account on Forge or Gerrit
 * whose username no source tells, or another TYPO3 account of the person, like one renamed to "old_…". Forge, Gerrit
 * and the TYPO3 accounts stay as they are: only Forger counts the account as the person's, see
 * App\People\UserDirectory. The index "user" is built from the sources again and again, so the links live here.
 */
#[ORM\Entity(repositoryClass: PersonLinkRepository::class)]
#[ORM\Table(name: 'person_link')]
#[ORM\UniqueConstraint(name: 'person_link_account', columns: ['account'])]
class PersonLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        /** The key of the account in UserDirectory: "forge:<number>", "gerrit:<number>" or a username */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $account,
        /** The TYPO3 username of the person the account belongs to */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $person,
        /** The username of the admin who linked it */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $linkedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $linkedAt,
    ) {
    }

    public function getAccount(): string
    {
        return $this->account;
    }

    public function getPerson(): string
    {
        return $this->person;
    }

    public function getLinkedBy(): string
    {
        return $this->linkedBy;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }
}
