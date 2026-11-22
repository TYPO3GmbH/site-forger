<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Api;

use App\Api\ActivityFilters;
use App\Api\InvalidParameter;
use App\Elastic\Indices;
use App\Enum\StreamKindEnum;
use App\Enum\StreamRangeEnum;
use App\Enum\StreamSourceEnum;
use App\People\UserDirectory;
use Elastica\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

final class ActivityFiltersTest extends TestCase
{
    public function testWithoutParametersItIsTheLastSevenDaysInUtcWithoutBots(): void
    {
        $filters = self::filters([]);

        self::assertSame(StreamRangeEnum::WEEK, $filters->window->range);
        self::assertSame('UTC', $filters->window->from->getTimezone()->getName());
        self::assertFalse($filters->filter->bots);
        self::assertNull($filters->personKey);
    }

    public function testItTakesTheFilterOfTheElements(): void
    {
        $filters = self::filters([
            'timeZone' => 'Europe/Berlin',
            'from' => '2026-11-02',
            'to' => '2026-11-08',
            'source' => 'gerrit',
            'kind' => 'voted,merged,voted',
            'branch' => '13.4',
        ]);

        self::assertSame('Europe/Berlin', $filters->window->from->getTimezone()->getName());
        self::assertSame('2026-11-02 to 2026-11-08', $filters->window->days());
        self::assertSame(StreamSourceEnum::GERRIT, $filters->filter->source);
        self::assertSame([StreamKindEnum::VOTED, StreamKindEnum::MERGED], $filters->filter->kinds);
        self::assertSame('13.4', $filters->filter->branch);
    }

    public function testAnEndLeftOutIsOpen(): void
    {
        $from = self::filters(['from' => '2026-11-02'])->window;
        $to = self::filters(['to' => '2026-11-08'])->window;

        self::assertSame('2026-11-02', $from->from->format('Y-m-d'));
        self::assertTrue($from->isOpen);
        self::assertSame('2004-01-01 to 2026-11-08', $to->days());
        self::assertFalse($to->isOpen);
    }

    public function testPeopleAreAnyOfTheirNumbers(): void
    {
        $filters = self::filters(['people' => 'forge:7, gerrit:9,12,forge:7']);

        self::assertSame([7, 12], $filters->filter->forgeIds);
        self::assertSame([9], $filters->filter->gerritIds);
        self::assertNull($filters->personKey);
    }

    public function testPeopleNobodyKnowsFindNothing(): void
    {
        $filters = self::filters(['people' => 'nobody-here']);

        self::assertSame([0], $filters->filter->forgeIds);
        self::assertSame([], $filters->filter->gerritIds);
        self::assertTrue($filters->filter->hasPerson());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function wrongParameters(): iterable
    {
        yield 'a time zone' => [['timeZone' => 'Mars/Base']];
        yield 'a kind' => [['kind' => 'voted,liked']];
        yield 'a day' => [['from' => '2026-13-01']];
        yield 'a time in the future' => [['since' => '2999-01-01T00:00:00Z']];
        yield 'a branch' => [['branch' => 'main; drop']];
        yield 'a range' => [['range' => '2w']];
        yield 'words of a name among the people' => [['people' => 'forge:1,Jane Doe']];
        yield 'a person and people' => [['person' => 'forge:1', 'people' => 'forge:2']];
        yield 'too many people' => [['people' => implode(',', range(1, ActivityFilters::MAX_PEOPLE + 1))]];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('wrongParameters')]
    public function testItRefusesAWrongValue(array $parameters): void
    {
        $this->expectException(InvalidParameter::class);

        self::filters($parameters);
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function filters(array $parameters): ActivityFilters
    {
        $users = new UserDirectory(new Indices(new Client()), new ArrayAdapter(), new NullLogger());

        return ActivityFilters::fromRequest(new Request($parameters), $users);
    }
}
