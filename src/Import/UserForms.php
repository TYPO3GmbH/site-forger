<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\People\User;
use App\Repository\UserRepository;

/**
 * Writes the users of an older form again in the current one, see User::SCHEMA: from what the index has, without
 * asking Forge or Gerrit. The job "Update users" runs it first, so the other steps find every user by its parts.
 */
final readonly class UserForms
{
    public const string STEP = 'Lift the users to the current form';

    /**
     * The users it writes per batch.
     */
    private const int BATCH = 1000;

    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * @return int the users written
     */
    public function lift(): int
    {
        $written = 0;
        do {
            $users = $this->users->inOlderForm(self::BATCH);
            $this->users->write($users);
            $written += count($users);
        } while (self::BATCH === count($users));

        return $written;
    }
}
