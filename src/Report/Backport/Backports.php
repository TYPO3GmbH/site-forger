<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report\Backport;

use App\Document\Change;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The rows of "Backports" from the file BackportFinder fills, see BackportFile: per change merged into main, the
 * versions of its "Releases:" footer and how far each one's backport is.
 *
 * A version is "missing" without a backport in Gerrit, else it has the backport change and its state. Versions the
 * change does not target are left out. The changes with a missing backport come first, then the others, which wait
 * for the review of theirs; within each the ones merged last first.
 */
final class Backports
{
    /**
     * The versions of the footer "Releases:" and their branches in Gerrit.
     */
    public const array VERSION_BRANCHES = [
        'main' => 'main',
        '6.2' => 'TYPO3_6-2',
        '7.6' => 'TYPO3_7-6',
        '8.7' => 'TYPO3_8-7',
        '9.5' => '9.5',
        '10.4' => '10.4',
        '11.5' => '11.5',
        '12.4' => '12.4',
        '13.4' => '13.4',
        '14.3' => '14.3',
    ];

    /**
     * The versions by their key in the file: "t3_134" => "13.4".
     *
     * @return array<string, string>
     */
    public static function versions(): array
    {
        $versions = [];
        foreach (array_keys(self::VERSION_BRANCHES) as $version) {
            $versions['t3_' . str_replace('.', '', (string) $version)] = (string) $version;
        }

        return $versions;
    }

    /**
     * The numbers of the changes merged into main, to load them from the review index.
     *
     * @param array<string, array<string, mixed>> $backports Change-Id => entry of the file
     *
     * @return list<int>
     */
    public static function mainNumbers(array $backports): array
    {
        return array_values(array_unique(array_map(static fn (array $entry): int => (int) ($entry['mainReviewId'] ?? 0), $backports)));
    }

    /**
     * @param array<string, array<string, mixed>> $backports Change-Id => entry of the file, the excluded ones left out
     * @param ReadableCollection<int, Change>     $changes   the changes in main by number
     *
     * @return ReadableCollection<int, BackportRow>
     */
    public static function rows(array $backports, ReadableCollection $changes): ReadableCollection
    {
        $names = self::versions();
        $rows = [];
        foreach ($backports as $changeId => $entry) {
            $versions = [];
            foreach ($entry['releases'] ?? [] as $key => $value) {
                $version = $names[$key] ?? null;
                // main is the change itself, "notTargeted" and "tbd" no backport to wait for
                if (null === $version || 'main' === $version || !(is_array($value) || 'missing' === $value)) {
                    continue;
                }
                $versions[] = new BackportVersion($version, is_array($value) ? Change::fromDocument($value) : null);
            }
            if ([] === $versions) {
                continue;
            }
            // The newest version first, as in the footer "Releases: main, 14.3, 13.4"
            usort(
                $versions,
                static fn (BackportVersion $a, BackportVersion $b): int => version_compare($b->version, $a->version),
            );
            $number = (int) ($entry['mainReviewId'] ?? 0);
            $rows[] = new BackportRow(
                (string) $changeId,
                $number,
                $changes->get($number),
                new ArrayCollection($versions),
            );
        }
        usort($rows, static fn (BackportRow $a, BackportRow $b): int => [$b->missing > 0, $b->change->updatedOn ?? '']
            <=> [$a->missing > 0, $a->change->updatedOn ?? '']);

        return new ArrayCollection($rows);
    }
}
