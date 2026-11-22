<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\PersonLinkDismissalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A suggestion an admin turned down: the account does not belong to the person, see App\People\LinkSuggestions.
 * The suggestion does not come again.
 */
#[ORM\Entity(repositoryClass: PersonLinkDismissalRepository::class)]
#[ORM\Table(name: 'person_link_dismissal')]
#[ORM\UniqueConstraint(name: 'person_link_dismissal_pair', columns: ['account', 'person'])]
class PersonLinkDismissal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        /** The key of the account, see PersonLink */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $account,
        /** The TYPO3 username of the person it was suggested for */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $person,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $dismissedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $dismissedAt,
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
}
