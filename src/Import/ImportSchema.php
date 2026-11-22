<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Elastic\Indices;

/**
 * Which version of the import wrote a document. A document has to be loaded again when its issue or change
 * changed, and when the import takes more or other data from Forge or Gerrit than when it was written.
 *
 * Raise the version of an index when its importer reads more or other data from the source, or derives fields
 * differently (ForgeIssueImporter::document(), GerritChangeImporter::documents()). The documents of an older
 * version are then loaded again over time: each delta of Forge and Gerrit takes a share of them (outdated()), and any import
 * that meets one loads it again too, see ImportWatermark::storedUpdates().
 * A change of the mapping alone needs no new version: app:elasticsearch:setup copies the documents over.
 * When the index already has the data of a new version, add a SchemaStep: SchemaBackfill then lifts the stored
 * documents without a request.
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
     * 3: the other relations of Forge, in "issue_relations", each with its type seen from the issue.
     * 4: the journals of every issue, not only of those under review: the comments of Forge.
     * 5: the attachments, in "attachments", without those added with a private note.
     */
    public const int ISSUE = 5;

    /**
     * 1: as before the versions
     * 2: what the changes imported before the versions may miss: the lowest and highest single vote of each label,
     *    work in progress for "[POC]" in the subject, the tag "!!!" from the subject.
     * 3: the owner of the change with the account of Gerrit, "owner_id" and "owner_username", and in the index
     *    "user"; "owner" and "ownerEmail" are those of the owner now, not of the author of the commit.
     * 4: the review log, the single votes, the files with their lines, the states of the labels, the conflicts,
     *    the change it is a cherry-pick of, the comments and their counts, and the relation chain and the merge
     *    conflicts of an open change, see GerritChangeImporter::documents(); the negative votes of the CI count in
     *    the sums and the lowest and highest votes, see GerritChangeImporter::labels().
     * 5: the day a change closed, "closed_on", and the day Gerrit submitted a merged one, "submitted", see
     *    GerritChangeImporter::closedOn(). The stored review log tells the day too: ChangeClosedOn lifts a change
     *    of version 4 without Gerrit. Such a change has no "submitted" until an import loads it again.
     */
    public const int REVIEW = 5;

    /**
     * @param 'issue'|'review' $index
     */
    public static function current(string $index): int
    {
        return Indices::ISSUE === $index ? self::ISSUE : self::REVIEW;
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
     * The documents an import of exactly this version wrote. Those without the field are of version 1.
     *
     * @return array<string, mixed>
     */
    public static function writtenBy(int $version): array
    {
        $ofVersion = ['term' => [self::FIELD => $version]];
        $withoutVersion = ['bool' => ['must_not' => [['exists' => ['field' => self::FIELD]]]]];

        return 1 === $version ? ['bool' => ['should' => [$ofVersion, $withoutVersion]]] : $ofVersion;
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
