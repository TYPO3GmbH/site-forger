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

    public static function notACursor(string $name): self
    {
        return new self(sprintf('The parameter "%s" is no cursor: use "next" of a page.', $name));
    }

    public static function beyondWindow(int $window): self
    {
        return new self(sprintf(
            'The parameters "offset" and "limit" together must not exceed %d. Narrow the search instead.',
            $window,
        ));
    }

    public static function needsOneOf(string $name, string $other): self
    {
        return new self(sprintf('The parameter "%s" or "%s" is required.', $name, $other));
    }

    public static function notTogether(string $name, string $other): self
    {
        return new self(sprintf('The parameters "%s" and "%s" do not go together. Use one of them.', $name, $other));
    }

    public static function tooMany(string $name, int $maximum): self
    {
        return new self(sprintf('The parameter "%s" takes at most %d values.', $name, $maximum));
    }

    public static function notSingle(string $name): self
    {
        return new self(sprintf('The parameter "%s" takes a single value.', $name));
    }

    public static function notWhole(string $name): self
    {
        return new self(sprintf('The parameter "%s" takes a whole number.', $name));
    }

    public static function notADay(string $name): self
    {
        return new self(sprintf('The parameter "%s" takes a day as YYYY-MM-DD, like 2026-11-21.', $name));
    }

    public static function notATimeZone(string $name): self
    {
        return new self(sprintf(
            'The parameter "%s" takes a time zone of the IANA database, like Europe/Berlin.',
            $name,
        ));
    }

    public static function notATime(string $name): self
    {
        return new self(sprintf(
            'The parameter "%s" takes a time in the past in ISO 8601, like 2026-11-21T08:30:00Z.',
            $name,
        ));
    }
}
