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
 * How far Forger knows the TYPO3 login of the people of Forge, and their email, see UserRepository::statistics() and
 * UserHealth.
 *
 * The TYPO3 login is the username on Forge, Gerrit and Keycloak: it joins a person across them. Forge tells it for
 * every user it knows. So a person without a login is either not asked for yet, or unknown to Forge.
 */
final readonly class UserStatistics
{
    /**
     * @param int             $notAskedYet    the users Forge was not asked for yet
     * @param int             $unknownToForge the users Forge does not know and whose login Forger does not know
     * @param int             $withoutEmail   the users Forge knows but shows no email of, and Gerrit neither
     * @param int             $gone           all users Forge does not know, also those with a login of before
     * @param array<int, int> $goneByStatus   the users unknown to Forge by the HTTP status Forge answered, 0 for
     *                                        those marked before it was stored
     */
    public function __construct(
        public int $total,
        public int $withLogin,
        public int $withEmail,
        public int $notAskedYet,
        public int $unknownToForge,
        public int $withoutEmail,
        public int $gone,
        public array $goneByStatus,
        public ?\DateTimeImmutable $lastFetched,
    ) {
    }

    public function withoutLogin(): int
    {
        return $this->total - $this->withLogin;
    }
}
