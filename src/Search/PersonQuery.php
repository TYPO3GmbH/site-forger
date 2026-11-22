<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Api\PersonFilter;

/**
 * The query of a person of the filters of the API, see PersonFilter: the number of the account, or all words in the
 * name, in any case and without accents, see NameSearch. The name is the one an issue or a change shows, which is
 * public.
 */
final class PersonQuery
{
    /**
     * @param string $numberField the field of the number, like "author.id"
     * @param string $nameField   the field of the name, like "author.name"
     *
     * @return array<string, mixed>
     */
    public static function query(PersonFilter $person, string $numberField, string $nameField): array
    {
        if (null !== $person->number) {
            return ['term' => [$numberField => $person->number]];
        }

        return ['bool' => ['must' => array_map(
            static fn (string $word): array => NameSearch::contains($nameField, $word),
            $person->words,
        )]];
    }
}
