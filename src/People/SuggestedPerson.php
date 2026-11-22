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
 * A person My Work suggests to follow: somebody new, with the first step the person made, see Following::newcomers().
 */
final readonly class SuggestedPerson
{
    /**
     * @param string                  $key  the key of the person in UserDirectory
     * @param string                  $step what the person did first, like "First change"
     * @param \DateTimeImmutable|null $date when, in UTC
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $avatar,
        public string $step,
        public ?\DateTimeImmutable $date,
    ) {
    }
}
