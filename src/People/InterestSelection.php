<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Entity\Interest;

/**
 * What the overview of My Work is narrowed to: all interests of the user, one of them, or everything.
 */
final readonly class InterestSelection
{
    public const string ALL = 'all';

    public const string MINE = 'mine';

    /**
     * @param string       $key        ALL, MINE or the id of an interest
     * @param list<string> $paths      the paths of the files of the interests picked
     * @param list<string> $categories the categories of the issues of the interests picked
     */
    private function __construct(
        public string $key,
        public array $paths,
        public array $categories,
    ) {
    }

    /**
     * The selection of the parameter "interest": an id of an interest of the user, ALL, or else the interests of the
     * user together. Without interests it is everything.
     *
     * @param list<Interest> $interests the interests of the user
     */
    public static function of(string $value, array $interests): self
    {
        if ([] === $interests || self::ALL === $value) {
            return new self(self::ALL, [], []);
        }
        foreach ($interests as $interest) {
            if ((string) $interest->getId() === $value) {
                return self::together((string) $interest->getId(), [$interest]);
            }
        }

        return self::together(self::MINE, $interests);
    }

    public function isEverything(): bool
    {
        return self::ALL === $this->key;
    }

    /**
     * @param list<Interest> $interests
     */
    private static function together(string $key, array $interests): self
    {
        $paths = [];
        $categories = [];
        foreach ($interests as $interest) {
            $paths = [...$paths, ...$interest->getPaths()];
            $categories = [...$categories, ...$interest->getCategories()];
        }

        return new self($key, array_values(array_unique($paths)), array_values(array_unique($categories)));
    }
}
