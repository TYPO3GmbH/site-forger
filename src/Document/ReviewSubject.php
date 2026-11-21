<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

/**
 * Reads what the subject of a Gerrit change says about it.
 */
final class ReviewSubject
{
    /**
     * Marks a breaking change, written as "[!!!]".
     */
    public const string BREAKING = '!!!';

    /**
     * Tags that mark a change as work in progress, like the status in Gerrit does.
     */
    public const array WORK_IN_PROGRESS = ['WIP', 'POC'];

    /**
     * The tags in square brackets, in their order, like "[!!!][FEATURE] ..." gives ['!!!', 'FEATURE'].
     *
     * @return list<string>
     */
    public static function tags(string $subject): array
    {
        preg_match_all('/\[([a-zA-Z0-9]+|!!!)\]/', $subject, $matches);

        return $matches[1];
    }

    /**
     * The subject without the tags in square brackets it starts with: "[!!!][FEATURE] Add a cache" gives "Add a
     * cache". A subject of tags only stays as it is.
     */
    public static function title(string $subject): string
    {
        $title = trim((string) preg_replace('/^(\s*\[([a-zA-Z0-9]+|!!!)\])+\s*/', '', $subject));

        return '' === $title ? trim($subject) : $title;
    }

    /**
     * Whether the tags mark the change as work in progress, "[WIP]" and "[POC]" do.
     *
     * @param list<string> $tags
     */
    public static function isWorkInProgress(array $tags): bool
    {
        return [] !== array_intersect($tags, self::WORK_IN_PROGRESS);
    }
}
