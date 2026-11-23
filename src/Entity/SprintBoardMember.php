<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person a restricted sprint board is shared with, by the username of the TYPO3 account, see SprintBoardVoter.
 * The person may look at the board; only managers change it.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'sprint_board_member_unique', columns: ['board_id', 'username'])]
class SprintBoardMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: SprintBoard::class, inversedBy: 'members')]
        #[ORM\JoinColumn(name: 'board_id', nullable: false, onDelete: 'CASCADE')]
        private SprintBoard $board,
        /** The username of the TYPO3 account, in lower case, as the login gives it */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $username,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->username = mb_strtolower($username);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBoard(): SprintBoard
    {
        return $this->board;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
