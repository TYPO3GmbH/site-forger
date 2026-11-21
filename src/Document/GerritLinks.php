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
 * The Gerrit changes an issue links to in its journals, mostly the notes Gerrit writes when a patch set is pushed.
 *
 * Documents of an import before version 4 of ImportSchema have the journals of the issues under review only.
 */
final class GerritLinks
{
    private const string PATTERN = '~https://review\.typo3\.org/(?:c/)?(?:Packages/TYPO3\.CMS/\+/)?(?<number>\d{2,6})~';

    /**
     * The numbers of the changes, in the order they were first linked.
     *
     * @param list<array<string, mixed>> $journals the journals of an issue, as Forge lists them
     *
     * @return list<int>
     */
    public static function inJournals(array $journals): array
    {
        $numbers = [];
        foreach ($journals as $journal) {
            preg_match_all(self::PATTERN, (string) ($journal['notes'] ?? ''), $matches);
            foreach ($matches['number'] as $number) {
                $numbers[] = (int) $number;
            }
        }

        return array_values(array_unique($numbers));
    }
}
