<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Entity\Interest;
use App\Search\ReviewLists;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * What the form of an interest sends, checked: a name, paths of files and categories of issues, see Interest.
 */
final readonly class InterestInput
{
    public const int MAX_PATHS = 20;

    private const int MAX_NAME = 100;

    private const int MAX_PATH = 200;

    /**
     * A path of the repository or a pattern with "*": no spaces, quotes or brackets.
     */
    private const string PATH = '~^[\w.*/@+-]+$~';

    /**
     * @param list<string> $paths
     * @param list<string> $categories
     * @param list<string> $errors     what the user has to fix, empty when the input is fine
     */
    private function __construct(
        public string $name,
        public array $paths,
        public array $categories,
        public array $errors,
    ) {
    }

    /**
     * @param InputBag<bool|float|int|string> $form       the fields "name", "paths" and "categories[]"
     * @param list<string>                    $categories the categories Forge has
     */
    public static function fromForm(InputBag $form, array $categories): self
    {
        $name = trim($form->getString('name'));
        // One path per line, or separated by commas, as in the filter "File" of the review lists
        $paths = ReviewLists::filePaths(str_replace(["\r\n", "\n"], ',', $form->getString('paths')));
        $picked = array_map(strval(...), $form->all('categories'));
        $known = array_values(array_intersect($categories, $picked));
        $errors = [];
        if ('' === $name || mb_strlen($name) > self::MAX_NAME) {
            $errors[] = sprintf('Give the interest a name of up to %d characters.', self::MAX_NAME);
        }
        if ([] === $paths && [] === $known) {
            $errors[] = 'Add at least one path of files or one category of issues.';
        }
        if (count($paths) > self::MAX_PATHS) {
            $errors[] = sprintf('An interest takes up to %d paths.', self::MAX_PATHS);
        }
        foreach ($paths as $path) {
            if (mb_strlen($path) > self::MAX_PATH || 1 !== preg_match(self::PATH, $path)) {
                $errors[] = sprintf('"%s" is no path of a file. Use paths like typo3/sysext/core/ or *.rst.', $path);
            }
        }

        return new self($name, $paths, $known, $errors);
    }

    public static function of(Interest $interest): self
    {
        return new self($interest->getName(), $interest->getPaths(), $interest->getCategories(), []);
    }

    public static function empty(): self
    {
        return new self('', [], [], []);
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}
