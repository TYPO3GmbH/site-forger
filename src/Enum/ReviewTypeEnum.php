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
 * The type of a Gerrit change, the tag in square brackets its subject starts with, like "[BUGFIX] ...".
 *
 * Gerrit has no field for it: the value is the tag as the subject has it, stored in "reviewtags" of the review index
 * with the other tags of the subject. The label is the name to show.
 */
enum ReviewTypeEnum: string
{
    // In this order a change with several of them takes its type, as the review cards did before
    case FEATURE = 'FEATURE';
    case TASK = 'TASK';
    case BUGFIX = 'BUGFIX';
    case DOCS = 'DOCS';
    case SECURITY = 'SECURITY';

    public function label(): string
    {
        return match ($this) {
            self::FEATURE => 'Feature',
            self::TASK => 'Task',
            self::BUGFIX => 'Bugfix',
            self::DOCS => 'Docs',
            self::SECURITY => 'Security',
        };
    }

    /**
     * The key of the type for styling: the left border of the review cards, "review-type-<severity>" of the
     * template bundle, and the tag, "tag-label-<severity>" in assets/css/module/_tags.scss.
     */
    public function severity(): string
    {
        return match ($this) {
            self::FEATURE => 'feature',
            self::TASK => 'task',
            self::BUGFIX => 'bugfix',
            self::DOCS, self::SECURITY => 'default',
        };
    }

    /**
     * The type of a change by the tags of its subject, null without one.
     *
     * @param list<string> $tags like ['!!!', 'FEATURE'], see App\Document\ReviewSubject::tags()
     */
    public static function fromTags(array $tags): ?self
    {
        foreach (self::cases() as $type) {
            if (in_array($type->value, $tags, true)) {
                return $type;
            }
        }

        return null;
    }
}
