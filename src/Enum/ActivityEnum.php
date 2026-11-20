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
 * How long ago an open Gerrit change or Forge issue was last updated.
 *
 * Neither Gerrit nor Forge has such a state: the stage is derived from "updated_on", which the review and the
 * issue index both have. Bots only vote on uploaded patch sets, so an update means that someone worked on it,
 * and no update that nobody did. It is computed when shown, not stored, since it changes with time and not
 * with the document. Closed documents (merged, abandoned, resolved, ...) have no stage. See ActivityQuery for
 * the stages in the indices.
 */
enum ActivityEnum
{
    case FRESH;
    case QUIET;
    case STALE;
    case ORPHANED;

    public function label(): string
    {
        return match ($this) {
            self::FRESH => 'Fresh',
            self::QUIET => 'Quiet',
            self::STALE => 'Stale',
            self::ORPHANED => 'Orphaned',
        };
    }

    /**
     * Whether a document shows its stage: only when it lies still. That a document is fresh is the normal case and
     * would be noise in every row; the filter and the help still name it.
     */
    public function isNoteworthy(): bool
    {
        return self::FRESH !== $this;
    }

    /**
     * The key of the stage for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return 'activity-' . strtolower($this->name);
    }

    public function icon(): string
    {
        return match ($this) {
            self::FRESH => 'actions-bolt',
            self::QUIET => 'actions-clock',
            self::STALE => 'actions-moon',
            self::ORPHANED => 'actions-archive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FRESH => 'Activity within the last 14 days',
            self::QUIET => 'No activity for 14 to 60 days',
            self::STALE => 'No activity for 60 to 180 days',
            self::ORPHANED => 'No activity for more than 180 days',
        };
    }

    /**
     * Days without activity the stage starts at.
     */
    public function minimumDays(): int
    {
        return match ($this) {
            self::FRESH => 0,
            self::QUIET => 14,
            self::STALE => 60,
            self::ORPHANED => 180,
        };
    }

    /**
     * Days without activity the stage ends before, null for the last stage.
     */
    public function maximumDays(): ?int
    {
        return match ($this) {
            self::FRESH => self::QUIET->minimumDays(),
            self::QUIET => self::STALE->minimumDays(),
            self::STALE => self::ORPHANED->minimumDays(),
            self::ORPHANED => null,
        };
    }

    public static function tryFromLabel(string $label): ?self
    {
        foreach (self::cases() as $stage) {
            if ($stage->label() === $label) {
                return $stage;
            }
        }

        return null;
    }

    public static function fromDate(\DateTimeInterface $lastActivity, \DateTimeInterface $now): self
    {
        $now = \DateTimeImmutable::createFromInterface($now);
        foreach ([self::ORPHANED, self::STALE, self::QUIET] as $stage) {
            if ($lastActivity < $now->modify('-' . $stage->minimumDays() . ' days')) {
                return $stage;
            }
        }

        return self::FRESH;
    }
}
