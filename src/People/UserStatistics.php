<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

/**
 * How far the people of Forge are mapped to an email, see UserRepository::statistics() and UserHealth.
 *
 * A person is mapped with an email Forge or Gerrit told. The others are not asked for yet, unknown to Forge, or
 * Forge does not show their email. These three add up to all people without an email.
 */
final readonly class UserStatistics
{
    /**
     * @param int             $gone         all users Forge does not know, also those with an email of before
     * @param array<int, int> $goneByStatus the unmapped gone users by the HTTP status Forge answered, 0 for those
     *                                      marked before it was stored
     */
    public function __construct(
        public int $total,
        public int $mapped,
        public int $notAskedYet,
        public int $unknownToForge,
        public int $withoutEmail,
        public int $gone,
        public array $goneByStatus,
        public ?\DateTimeImmutable $lastFetched,
    ) {
    }

    public function unmapped(): int
    {
        return $this->total - $this->mapped;
    }
}
