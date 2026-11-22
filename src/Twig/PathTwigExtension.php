<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\Search\ReviewLists;
use Twig\Attribute\AsTwigFilter;

/**
 * Sorts the files of a change as a file tree shows them, and splits the paths of the filter "File".
 */
final class PathTwigExtension
{
    /**
     * @return list<string>
     */
    #[AsTwigFilter(name: 'file_paths')]
    public static function filePaths(string $paths): array
    {
        return ReviewLists::filePaths($paths);
    }

    /**
     * On every level the folders first, then the files, each by name: case does not count, numbers count as
     * numbers. So the files of a folder stand together, and those in the root come last.
     *
     * @param iterable<string> $paths
     *
     * @return list<string>
     */
    #[AsTwigFilter(name: 'sort_paths')]
    public static function sortPaths(iterable $paths): array
    {
        $sorted = [...$paths];
        usort($sorted, self::compare(...));

        return $sorted;
    }

    private static function compare(string $a, string $b): int
    {
        $partsA = explode('/', $a);
        $partsB = explode('/', $b);
        $levels = min(count($partsA), count($partsB));
        for ($level = 0; $level < $levels; ++$level) {
            $isFolderA = $level < count($partsA) - 1;
            $isFolderB = $level < count($partsB) - 1;
            if ($isFolderA !== $isFolderB) {
                return $isFolderA ? -1 : 1;
            }
            $byName = strnatcasecmp($partsA[$level], $partsB[$level]);
            if (0 !== $byName) {
                return $byName;
            }
        }

        // The same path but for case
        return strcmp($a, $b);
    }
}
