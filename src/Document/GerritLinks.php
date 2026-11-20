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
 * The import keeps the journals of the issues under review only, the others link no change here.
 */
final class GerritLinks
{
    private const string PATTERN = '~https://review\.typo3\.org/(?:c/)?(?:Packages/TYPO3\.CMS/\+/)?(?<number>\d{2,6})~';

    /**
     * The numbers of the changes, in the order they were first linked.
     *
     * @param array<string, mixed> $issue the issue as it is indexed
     *
     * @return list<int>
     */
    public static function changeNumbers(array $issue): array
    {
        $numbers = [];
        foreach ($issue['journals'] ?? [] as $journal) {
            preg_match_all(self::PATTERN, (string) ($journal['notes'] ?? ''), $matches);
            foreach ($matches['number'] as $number) {
                $numbers[] = (int) $number;
            }
        }

        return array_values(array_unique($numbers));
    }
}
