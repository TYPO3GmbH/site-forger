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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChartRegistryTest extends TestCase
{
    #[DataProvider('chartsDataProvider')]
    public function testChartReadsTheIndexOfItsDocumentType(string $identifier): void
    {
        $chart = ChartRegistry::getChartInstance($identifier);
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
        ChartRegistry::getChartInstance('does_not_exist');
    }

    public static function chartsDataProvider(): \Generator
    {
        $charts = (new \ReflectionClassConstant(ChartRegistry::class, 'CHARTS'))->getValue();
        foreach (array_keys($charts) as $identifier) {
            yield $identifier => [$identifier];
        }
    }
}
