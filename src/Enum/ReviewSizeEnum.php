<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * How big a Gerrit change is, by the lines it adds and removes together, as Gerrit counts them.
 *
 * Gerrit has no sizes: the label is the name to show, see ReviewSizeQuery for the sizes in the index. The size
 * tells a reviewer at a glance how long the review takes, named like the difficulty of a game, from Easy to
 * Impossible.
 */
enum ReviewSizeEnum
{
    case EASY;
    case NORMAL;
    case HARD;
    case EXPERT;
    case NIGHTMARE;
    case IMPOSSIBLE;

    public function label(): string
    {
        return match ($this) {
            self::EASY => 'Easy',
            self::NORMAL => 'Normal',
            self::HARD => 'Hard',
            self::EXPERT => 'Expert',
            self::NIGHTMARE => 'Nightmare',
            self::IMPOSSIBLE => 'Impossible',
        };
    }

    /**
     * The key of the size for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return 'size-' . strtolower($this->name);
    }

    /**
     * The most lines a change of the size has, null for the biggest.
     */
    public function maximum(): ?int
    {
        return match ($this) {
            self::EASY => 50,
            self::NORMAL => 250,
            self::HARD => 1000,
            self::EXPERT => 2500,
            self::NIGHTMARE => 10000,
            self::IMPOSSIBLE => null,
        };
    }

    /**
     * The fewest lines a change of the size has: one more than the size below it has at most.
     */
    public function minimum(): int
    {
        $previous = null;
        foreach (self::cases() as $size) {
            if ($size === $this) {
                return null === $previous ? 0 : (int) $previous->maximum() + 1;
            }
            $previous = $size;
        }

        return 0;
    }

    public function description(): string
    {
        $maximum = $this->maximum();
        if (null !== $maximum) {
            return sprintf('up to %d lines changed', $maximum);
        }

        return sprintf('more than %d lines changed', $this->minimum() - 1);
    }

    public static function tryFromLabel(string $label): ?self
    {
        foreach (self::cases() as $size) {
            if ($size->label() === $label) {
                return $size;
            }
        }

        return null;
    }

    public static function fromLines(int $lines): self
    {
        foreach (self::cases() as $size) {
            $maximum = $size->maximum();
            if (null === $maximum || $lines <= $maximum) {
                return $size;
            }
        }

        return self::IMPOSSIBLE;
    }
}
