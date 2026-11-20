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
 * Gerrit has no sizes: the value is the key of the size, the label the name to show. The size tells a reviewer
 * at a glance how long the review takes, named like the difficulty of a game, from Easy to Impossible.
 */
enum ReviewSizeEnum: string
{
    /**
     * The lines of a change in Painless, 0 for one imported before the lines were.
     */
    public const string LINES_SCRIPT = "(doc['insertions'].size() == 0 ? 0 : doc['insertions'].value) + (doc['deletions'].size() == 0 ? 0 : doc['deletions'].value)";

    case EASY = 'easy';
    case NORMAL = 'normal';
    case HARD = 'hard';
    case EXPERT = 'expert';
    case NIGHTMARE = 'nightmare';
    case IMPOSSIBLE = 'impossible';

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
        return 'size-' . $this->value;
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

    /**
     * The Gerrit changes of the review index of this size. A change imported without lines has none.
     *
     * @return array<string, mixed>
     */
    public function changeQuery(): array
    {
        return ['bool' => ['filter' => [
            ['exists' => ['field' => 'insertions']],
            ['script' => ['script' => [
                'lang' => 'painless',
                'source' => 'def lines = ' . self::LINES_SCRIPT . '; return lines >= params.minimum && (params.maximum < 0 || lines <= params.maximum);',
                'params' => ['minimum' => $this->minimum(), 'maximum' => $this->maximum() ?? -1],
            ]]],
        ]]];
    }

    /**
     * A "filters" aggregation counting the changes per size, see buckets().
     *
     * @return array<string, mixed>
     */
    public static function changeAggregation(): array
    {
        $filters = [];
        foreach (self::cases() as $size) {
            $filters[$size->label()] = $size->changeQuery();
        }

        return ['filters' => ['filters' => $filters]];
    }

    /**
     * The result of changeAggregation() as the filter list shows aggregations: a bucket per size, the smallest
     * first, with the label as key.
     *
     * @param array<string, mixed> $result
     *
     * @return array{buckets: list<array{key: string, doc_count: int}>}
     */
    public static function buckets(array $result): array
    {
        return ['buckets' => array_map(
            static fn (self $size): array => ['key' => $size->label(), 'doc_count' => (int) ($result['buckets'][$size->label()]['doc_count'] ?? 0)],
            self::cases(),
        )];
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

    /**
     * The size of a document of the review index, null if it was imported before the lines were.
     *
     * @param array<string, mixed> $change
     */
    public static function fromChange(array $change): ?self
    {
        if (!isset($change['insertions'], $change['deletions'])) {
            return null;
        }

        return self::fromLines((int) $change['insertions'] + (int) $change['deletions']);
    }
}
