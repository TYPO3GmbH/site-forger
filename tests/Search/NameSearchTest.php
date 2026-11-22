<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Search\NameSearch;
use PHPUnit\Framework\TestCase;

final class NameSearchTest extends TestCase
{
    public function testFoldsLikeTheNormalizerOfTheIndex(): void
    {
        self::assertSame('andre kraus', NameSearch::fold('André Kraus'));
        self::assertSame('soren strasse lukasz aero', NameSearch::fold('Søren Straße Łukasz Ærø'));
    }

    public function testSearchesTheFoldedSubfield(): void
    {
        self::assertSame(
            ['wildcard' => ['author.name.folded' => ['value' => '*andre*']]],
            NameSearch::contains('author.name', 'André'),
        );
        self::assertSame(
            ['wildcard' => ['assigned_to.name.folded' => ['value' => '*a\\*b*']]],
            NameSearch::contains('assigned_to.name.keyword', 'A*b'),
        );
    }
}
