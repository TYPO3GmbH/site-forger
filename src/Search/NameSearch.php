<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use Symfony\Component\String\UnicodeString;

/**
 * The search for a part of a name, in any case and without accents: "andre kraus" finds "André Kraus". Each name
 * field has the subfield "folded", which keeps the name in lower case and ASCII, see the normalizer "folded" of the
 * index configurations. The word is folded the same way here.
 */
final class NameSearch
{
    /**
     * A name field holds a part of the word, like "*andre*".
     *
     * @param string $field the name field, like "author.name", or its keyword subfield, like "assigned_to.name.keyword"
     *
     * @return array<string, mixed>
     */
    public static function contains(string $field, string $word): array
    {
        return ['wildcard' => [self::foldedField($field) => [
            'value' => '*' . addcslashes(self::fold($word), '\\*?') . '*',
        ]]];
    }

    /**
     * Lower case and ASCII, like the normalizer "folded": "André" becomes "andre", "Straße" "strasse".
     */
    public static function fold(string $word): string
    {
        return new UnicodeString($word)->ascii()->lower()->toString();
    }

    private static function foldedField(string $field): string
    {
        return (str_ends_with($field, '.keyword') ? substr($field, 0, -strlen('.keyword')) : $field) . '.folded';
    }
}
