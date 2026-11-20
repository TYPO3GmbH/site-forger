<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

/**
 * Which version of the import wrote a document. A document has to be loaded again when its issue or change
 * changed, and when the import takes more or other data from Forge or Gerrit than when it was written.
 *
 * Raise the version of an index when its importer reads more or other data from the source, or derives fields
 * differently (ForgeIssueImporter::document(), GerritChangeImporter::documents()). The documents of an older
 * version are then loaded again over time: each delta of Forge and Gerrit takes a share of them (outdated()), and any import
 * that meets one loads it again too, see ImportWatermark::storedUpdates().
 * A change of the mapping alone needs no new version: app:elasticsearch:setup copies the documents over.
 *
 * Documents without the field were written before it existed, by version 1.
 */
final class ImportSchema
{
    public const string FIELD = 'import_schema';

    /**
     * 1: as before the versions
     * 2: the "duplicates" relations of Forge, in "duplicates" and "duplicated_by"; issues imported before the
     *    import kept the email of the assignee get it, too.
     */
    public const int ISSUE = 2;

    /**
     * 1: as before the versions
     * 2: what the changes imported before the versions may miss: the lowest and highest single vote of each label,
     *    work in progress for "[POC]" in the subject, the tag "!!!" from the subject.
     */
    public const int REVIEW = 2;

    /**
     * @param 'issue'|'review' $index
     */
    public static function current(string $index): int
    {
        return 'issue' === $index ? self::ISSUE : self::REVIEW;
    }

    /**
     * The documents an older version of the import wrote, the ones to load again.
     *
     * @param 'issue'|'review' $index
     *
     * @return array<string, mixed>
     */
    public static function outdated(string $index): array
    {
        // A range on the field does not match documents without it, must_not of it does
        return ['bool' => ['must_not' => [['range' => [self::FIELD => ['gte' => self::current($index)]]]]]];
    }

    /**
     * @param 'issue'|'review' $index
     * @param mixed            $stored the value of FIELD in a document, null when it has none
     */
    public static function isCurrent(string $index, mixed $stored): bool
    {
        return (int) ($stored ?? 1) >= self::current($index);
    }
}
