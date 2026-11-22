<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\People\Person;

/**
 * A partner in the reviews of a person: who reviewed the changes of the person, or whose changes the person
 * reviewed: the changes and the votes and comments on them. See ReviewExchange.
 */
final readonly class ReviewPartner
{
    public function __construct(
        public Person $person,
        public int $changes,
        public int $reviews,
    ) {
    }
}
