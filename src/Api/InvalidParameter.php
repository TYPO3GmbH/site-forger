<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

/**
 * A query parameter of the API has a value the endpoint does not accept. The API answers 400 with the message.
 */
final class InvalidParameter extends \InvalidArgumentException
{
    /**
     * @param list<string> $allowed
     */
    public static function notOneOf(string $name, string $value, array $allowed): self
    {
        return new self(sprintf(
            'The parameter "%s" does not accept "%s". Use one of: %s.',
            $name,
            $value,
            implode(', ', $allowed),
        ));
    }

    public static function outOfRange(string $name, int $minimum, int $maximum): self
    {
        return new self(sprintf('The parameter "%s" must be between %d and %d.', $name, $minimum, $maximum));
    }

    public static function beyondWindow(int $window): self
    {
        return new self(sprintf(
            'The parameters "offset" and "limit" together must not exceed %d. Narrow the search instead.',
            $window,
        ));
    }

    public static function notSingle(string $name): self
    {
        return new self(sprintf('The parameter "%s" takes a single value.', $name));
    }

    public static function notWhole(string $name): self
    {
        return new self(sprintf('The parameter "%s" takes a whole number.', $name));
    }
}
