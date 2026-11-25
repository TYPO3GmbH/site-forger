<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\ElasticsearchVersion;
use App\Enum\SystemCheckEnum;
use App\Import\ImportSchema;
use App\Import\PendingImport;
use App\Stream\StreamSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SystemChecksTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, SystemCheckEnum}>
     */
    public static function versions(): iterable
    {
        yield 'production before the upgrade' => ['7.17.29', SystemCheckEnum::OK];
        yield 'dev' => ['8.19.25', SystemCheckEnum::OK];
        yield 'first with case_insensitive' => ['7.10.0', SystemCheckEnum::OK];
        yield 'too old for the search in filters' => ['7.9.3', SystemCheckEnum::PROBLEM];
        yield 'a major the queries are not checked with' => ['9.5.5', SystemCheckEnum::PROBLEM];
        yield 'unknown' => [null, SystemCheckEnum::UNKNOWN];
    }

    #[DataProvider('versions')]
    public function testVersion(?string $version, SystemCheckEnum $expected): void
    {
        self::assertSame($expected, ElasticsearchVersion::result($version));
    }

    public function testDocumentsOfAnOlderImportWait(): void
    {
        self::assertSame(SystemCheckEnum::ATTENTION, PendingImport::result(576));
        self::assertSame(SystemCheckEnum::OK, PendingImport::result(0));
    }

    public function testTheEventsOfAnOlderStreamVersionWaitForTheBackfill(): void
    {
        self::assertSame(
            [
                ['version' => StreamSchema::VERSION, 'documents' => 60, 'state' => 'current'],
                ['version' => 0, 'documents' => 40, 'state' => 'backfill'],
            ],
            PendingImport::streamVersions([0 => 40, StreamSchema::VERSION => 60]),
        );
    }

    public function testEachIndexCountsTheDocumentsOfAnOlderImport(): void
    {
        $checks = PendingImport::checks();

        self::assertSame(['issue', 'review'], array_column($checks, 'index'));
        foreach ($checks as $check) {
            self::assertSame(ImportSchema::current($check['index']), $check['version'], $check['label']);
            self::assertSame(ImportSchema::outdated($check['index']), $check['query'], $check['label']);
            self::assertNotSame('', $check['description']);
        }
    }

    public function testTheWorstResultWins(): void
    {
        self::assertSame(SystemCheckEnum::OK, SystemCheckEnum::worst());
        self::assertSame(SystemCheckEnum::OK, SystemCheckEnum::worst(SystemCheckEnum::OK, SystemCheckEnum::OK));
        self::assertSame(SystemCheckEnum::UNKNOWN, SystemCheckEnum::worst(SystemCheckEnum::OK, SystemCheckEnum::UNKNOWN));
        self::assertSame(SystemCheckEnum::ATTENTION, SystemCheckEnum::worst(SystemCheckEnum::UNKNOWN, SystemCheckEnum::ATTENTION));
        self::assertSame(SystemCheckEnum::PROBLEM, SystemCheckEnum::worst(SystemCheckEnum::ATTENTION, SystemCheckEnum::PROBLEM, SystemCheckEnum::OK));
    }
}
