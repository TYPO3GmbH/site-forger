<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\FollowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person the logged-in user follows: My Work shows the activity of the person, see FollowController. Only the
 * follower sees it. The person does not learn about it.
 */
#[ORM\Entity(repositoryClass: FollowRepository::class)]
#[ORM\Table(name: 'follow')]
#[ORM\UniqueConstraint(name: 'follow_follower_person', columns: ['follower', 'person'])]
class Follow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        /** The username of the TYPO3 account that follows */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $follower,
        /** The key of the person in UserDirectory: the username, or "forge:<number>" and "gerrit:<number>" */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $person,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $followedAt,
    ) {
    }

    public function getFollower(): string
    {
        return $this->follower;
    }

    public function getPerson(): string
    {
        return $this->person;
    }

    public function getFollowedAt(): \DateTimeImmutable
    {
        return $this->followedAt;
    }
}
