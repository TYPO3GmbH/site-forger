<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\Follow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The people the users follow, see Follow.
 *
 * @extends ServiceEntityRepository<Follow>
 */
class FollowRepository extends ServiceEntityRepository
{
    /**
     * The people one user may follow. The activity API takes as many in its parameter "people".
     */
    public const int LIMIT = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Follow::class);
    }

    /**
     * @return bool whether the user follows the person now: false when the user follows LIMIT people already
     */
    public function follow(string $follower, string $person, \DateTimeImmutable $now): bool
    {
        if ($this->isFollowing($follower, $person)) {
            return true;
        }
        if ($this->count(['follower' => $follower]) >= self::LIMIT) {
            return false;
        }
        try {
            $this->getEntityManager()->getConnection()->insert('follow', [
                'follower' => $follower,
                'person' => $person,
                'followed_at' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A second request followed the person at the same time
        }

        return true;
    }

    public function unfollow(string $follower, string $person): void
    {
        $this->getEntityManager()->getConnection()->delete('follow', ['follower' => $follower, 'person' => $person]);
    }

    public function isFollowing(string $follower, string $person): bool
    {
        return $this->count(['follower' => $follower, 'person' => $person]) > 0;
    }

    /**
     * The keys of the people the user follows, the latest first.
     *
     * @return list<string>
     */
    public function peopleOf(string $follower): array
    {
        return array_map(
            static fn (Follow $follow): string => $follow->getPerson(),
            $this->findBy(['follower' => $follower], ['followedAt' => 'DESC', 'id' => 'DESC']),
        );
    }
}
