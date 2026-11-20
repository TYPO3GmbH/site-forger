<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Charts;

use App\Charts\ChartRegistry;
use App\Charts\Types\AbstractChart;
use App\Charts\Types\IssueChart;
use App\Charts\Types\ReviewChart;
use App\Elastic\Indices;
use Elastica\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ChartRegistryTest extends TestCase
{
    #[DataProvider('chartsDataProvider')]
    public function testChartReadsTheIndexOfItsDocumentType(string $identifier): void
    {
        $chart = self::registry()->get($identifier);
        self::assertInstanceOf(AbstractChart::class, $chart);

        $expected = match (true) {
            $chart instanceof IssueChart => 'issue',
            $chart instanceof ReviewChart => 'review',
            default => self::fail($chart::class . ' is neither an issue nor a review chart'),
        };
        $documentType = (new \ReflectionMethod($chart, 'getDocumentType'))->invoke($chart);

        self::assertSame($expected, $documentType);
    }

    public function testUnknownChartIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::registry()->get('does_not_exist');
    }

    public static function chartsDataProvider(): \Generator
    {
        foreach (array_keys(ChartRegistry::CHARTS) as $identifier) {
            yield $identifier => [$identifier];
        }
    }

    private static function registry(): ChartRegistry
    {
        $indices = new Indices(new Client());

        return new ChartRegistry(new ServiceLocator(array_map(static fn (string $class): \Closure => static fn (): object => new $class($indices), ChartRegistry::CHARTS)));
    }
}
