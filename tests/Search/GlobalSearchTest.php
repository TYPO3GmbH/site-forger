<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Search\GlobalSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlobalSearchTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function searchWords(): iterable
    {
        yield 'number' => ['81234', 81234];
        yield 'with hash' => ['#91089', 91089];
        yield 'with spaces' => [' 91089 ', 91089];
        yield 'words' => ['cache 91089', null];
        yield 'zero first' => ['081234', null];
        yield 'empty' => ['', null];
        yield 'too long' => ['1234567890', null];
    }

    #[DataProvider('searchWords')]
    public function testANumberAloneFindsTheIssueAndTheChangeWithIt(string $searchTerms, ?int $number): void
    {
        self::assertSame($number, GlobalSearch::number($searchTerms));
    }
}
