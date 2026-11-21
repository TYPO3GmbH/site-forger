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
 * A person of a filter of the API: a number, or words of the name.
 *
 * A number is the account on Forge for issues and on Gerrit for changes, and names one person. Words are searched in
 * the name an issue or a change shows, so they may fit several people: the items of the answer tell which.
 */
final readonly class PersonFilter
{
    /**
     * @param list<string> $words lower case
     */
    private function __construct(
        public ?int $number,
        public array $words,
    ) {
    }

    public static function fromValue(string $value): ?self
    {
        $value = trim($value);
        if (1 === preg_match('/^\d{1,9}$/', $value)) {
            return new self((int) $value, []);
        }
        $words = preg_split('/\s+/', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [] === $words ? null : new self(null, $words);
    }

    public static function number(int $number): self
    {
        return new self($number, []);
    }

    /**
     * @param list<string> $words
     */
    public static function name(array $words): self
    {
        return new self(null, array_map(mb_strtolower(...), $words));
    }
}
