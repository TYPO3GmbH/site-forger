<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Api\PersonFilter;
use App\Search\PersonQuery;
use PHPUnit\Framework\TestCase;

final class PersonQueryTest extends TestCase
{
    public function testFindsAPersonByTheNumber(): void
    {
        self::assertSame(
            ['term' => ['author.id' => 36415]],
            PersonQuery::query(PersonFilter::number(36415), 'author.id', 'author.name'),
        );
    }

    public function testFindsAllWordsInTheNameInAnyCase(): void
    {
        $query = PersonQuery::query(PersonFilter::name(['Frank', 'Näg*ler']), 'author.id', 'author.name');

        self::assertSame(
            ['bool' => ['must' => [
                ['wildcard' => ['author.name.folded' => ['value' => '*frank*']]],
                ['wildcard' => ['author.name.folded' => ['value' => '*nag\\*ler*']]],
            ]]],
            $query,
        );
    }

    public function testTakesDigitsForANumberAndTheRestForWords(): void
    {
        self::assertSame(36415, PersonFilter::fromValue(' 36415 ')?->number);
        self::assertSame(['wouter', 'wolters'], PersonFilter::fromValue('Wouter  Wolters')?->words);
        self::assertNull(PersonFilter::fromValue(''));
    }
}
