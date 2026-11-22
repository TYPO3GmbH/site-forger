<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\AccountRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An account an admin wants asked from its source again, "forge:<number>" or "gerrit:<number>", see
 * App\Controller\PeopleController::requestAccounts(). The job "Update users" asks for these first and then removes
 * them, see App\Command\ImportUsersCommand.
 */
#[ORM\Entity(repositoryClass: AccountRequestRepository::class)]
#[ORM\Table(name: 'account_request')]
class AccountRequest
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $account,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $requestedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $requestedAt,
    ) {
    }

    public function getAccount(): string
    {
        return $this->account;
    }
}
