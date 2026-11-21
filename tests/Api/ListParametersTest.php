<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Api;

use App\Api\InvalidParameter;
use App\Api\ListParameters;
use App\Enum\IssueStateFilterEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListParametersTest extends TestCase
{
    public function testTakesTheDefaultsWithoutParameters(): void
    {
        $list = ListParameters::fromRequest(Request::create('/api/v1/issues'));

        self::assertSame('', $list->query);
        self::assertSame(ListParameters::DEFAULT_LIMIT, $list->limit);
        self::assertSame(0, $list->offset);
    }

    public function testTakesTheSearchWordsAndThePage(): void
    {
        $list = ListParameters::fromRequest(Request::create('/api/v1/issues?q=%20cache%20&limit=100&offset=9900'));

        self::assertSame('cache', $list->query);
        self::assertSame(100, $list->limit);
        self::assertSame(9900, $list->offset);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'no limit' => ['limit=0'];
        yield 'a limit above the maximum' => ['limit=101'];
        yield 'a limit that is no number' => ['limit=ten'];
        yield 'a negative offset' => ['offset=-1'];
        yield 'a page beyond the window of Elasticsearch' => ['offset=9990&limit=20'];
        yield 'several search words parameters' => ['q[]=a&q[]=b'];
    }

    #[DataProvider('invalidQueries')]
    public function testRejectsAValueTheListDoesNotAccept(string $query): void
    {
        $this->expectException(InvalidParameter::class);

        ListParameters::fromRequest(Request::create('/api/v1/issues?' . $query));
    }

    public function testTakesAChoiceOrItsDefault(): void
    {
        $default = IssueStateFilterEnum::OPEN;

        self::assertSame(
            IssueStateFilterEnum::ALL,
            ListParameters::choice(Request::create('/?status=all'), 'status', IssueStateFilterEnum::class, $default),
        );
        self::assertSame(
            $default,
            ListParameters::choice(Request::create('/'), 'status', IssueStateFilterEnum::class, $default),
        );
    }

    public function testNamesTheValuesOfAChoice(): void
    {
        $this->expectExceptionMessage('The parameter "status" does not accept "bogus". Use one of: open, closed, all.');

        ListParameters::choice(
            Request::create('/?status=bogus'),
            'status',
            IssueStateFilterEnum::class,
            IssueStateFilterEnum::OPEN,
        );
    }

    public function testTreatsAnEmptyTextAsMissing(): void
    {
        self::assertNull(ListParameters::optional(Request::create('/?tracker=%20'), 'tracker'));
        self::assertSame('Bug', ListParameters::optional(Request::create('/?tracker=Bug'), 'tracker'));
    }

    public function testTakesANumberInItsRange(): void
    {
        self::assertSame(10, ListParameters::number(Request::create('/'), 'limit', 10, 1, 25));
        self::assertSame(25, ListParameters::number(Request::create('/?limit=25'), 'limit', 10, 1, 25));

        $this->expectException(InvalidParameter::class);
        ListParameters::number(Request::create('/?limit=26'), 'limit', 10, 1, 25);
    }
}
