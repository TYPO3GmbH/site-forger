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
 * A person a user follows, as My Work lists it, see Following.
 */
final readonly class FollowedPerson
{
    /**
     * @param string              $key      the key of the person in UserDirectory, see Follow
     * @param string|null         $username the username on Forge and Gerrit, public there
     * @param PersonActivity|null $activity what the person did lately, for the cards of the people followed
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $avatar,
        public ?string $username = null,
        public ?PersonActivity $activity = null,
    ) {
    }
}
