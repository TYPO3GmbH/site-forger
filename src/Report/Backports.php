<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Command\BackportCommand;
use App\Enum\ReviewStateEnum;

/**
 * The rows of "Backports" from var/backports.json, which BackportCommand writes: per change merged into main, the
 * versions of its "Releases:" footer and how far each one's backport is.
 *
 * A version is "missing" without a backport in Gerrit, else it has the backport change and its state. Versions the
 * change does not target are left out. The changes with a missing backport come first, then the others, which wait
 * for the review of theirs; within each the ones merged last first.
 */
final class Backports
{
    /**
     * The versions by their key in the file: "t3_134" => "13.4".
     *
     * @return array<string, string>
     */
    public static function versions(): array
    {
        $versions = [];
        foreach (array_keys(BackportCommand::VERSION_BRANCHES) as $version) {
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
     * @param array<int, array<string, mixed>>    $changes   number => document of the review index, the changes in main
     *
     * @return list<array{changeId: string, number: int, change: array<string, mixed>|null, missing: int, versions: list<array{version: string, state: ReviewStateEnum|null, change: array<string, mixed>|null}>}>
     */
    public static function rows(array $backports, array $changes): array
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
                $versions[] = is_array($value)
                    ? ['version' => $version, 'state' => ReviewStateEnum::fromChange($value), 'change' => $value]
                    : ['version' => $version, 'state' => null, 'change' => null];
            }
            if ([] === $versions) {
                continue;
            }
            // The newest version first, as in the footer "Releases: main, 14.3, 13.4"
            usort($versions, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
            $number = (int) ($entry['mainReviewId'] ?? 0);
            $rows[] = [
                'changeId' => (string) $changeId,
                'number' => $number,
                'change' => $changes[$number] ?? null,
                'missing' => count(array_filter($versions, static fn (array $version): bool => null === $version['state'])),
                'versions' => $versions,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['missing'] > 0, $b['change']['updated_on'] ?? ''] <=> [$a['missing'] > 0, $a['change']['updated_on'] ?? '']);

        return $rows;
    }
}
