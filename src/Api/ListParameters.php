<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * The parameters every list of the API takes: the search words and the page.
 */
final readonly class ListParameters
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_LIMIT = 100;

    /**
     * Elasticsearch returns no hit beyond this position ("index.max_result_window").
     */
    public const int MAX_WINDOW = 10000;

    public function __construct(
        public string $query = '',
        public int $limit = self::DEFAULT_LIMIT,
        public int $offset = 0,
    ) {
    }

    /**
     * @throws InvalidParameter
     */
    public static function fromRequest(Request $request): self
    {
        $limit = self::number($request, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $offset = self::integer($request, 'offset', 0);
        if ($offset < 0 || $offset + $limit > self::MAX_WINDOW) {
            throw InvalidParameter::beyondWindow(self::MAX_WINDOW);
        }

        return new self(trim(self::string($request, 'q')), $limit, $offset);
    }

    /**
     * A value from a list of allowed ones, the default without the parameter.
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     * @param T               $default
     *
     * @return T
     *
     * @throws InvalidParameter
     */
    public static function choice(Request $request, string $name, string $enum, \BackedEnum $default): \BackedEnum
    {
        $value = self::string($request, $name);
        if ('' === $value) {
            return $default;
        }

        $choice = $enum::tryFrom($value);
        if (null === $choice) {
            $allowed = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
            throw InvalidParameter::notOneOf($name, $value, $allowed);
        }

        return $choice;
    }

    /**
     * A text parameter, null when it is missing or empty.
     *
     * @throws InvalidParameter
     */
    public static function optional(Request $request, string $name): ?string
    {
        $value = trim(self::string($request, $name));

        return '' === $value ? null : $value;
    }

    /**
     * A whole number between a minimum and a maximum, the default without the parameter.
     *
     * @throws InvalidParameter
     */
    public static function number(Request $request, string $name, int $default, int $minimum, int $maximum): int
    {
        $value = self::integer($request, $name, $default);
        if ($value < $minimum || $value > $maximum) {
            throw InvalidParameter::outOfRange($name, $minimum, $maximum);
        }

        return $value;
    }

    /**
     * @throws InvalidParameter
     */
    private static function string(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? '';
        if (!is_scalar($value)) {
            throw InvalidParameter::notSingle($name);
        }

        return (string) $value;
    }

    /**
     * @throws InvalidParameter
     */
    private static function integer(Request $request, string $name, int $default): int
    {
        $value = self::string($request, $name);
        if ('' === $value) {
            return $default;
        }
        if (1 !== preg_match('/^\d{1,9}$/', $value)) {
            throw InvalidParameter::notWhole($name);
        }

        return (int) $value;
    }
}
