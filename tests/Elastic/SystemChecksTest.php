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
use App\Elastic\PendingImport;
use App\Enum\SystemCheckEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SystemChecksTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, SystemCheckEnum}>
     */
    public static function versions(): iterable
    {
        yield 'dev' => ['7.17.3', SystemCheckEnum::OK];
        yield 'first with case_insensitive' => ['7.10.0', SystemCheckEnum::OK];
        yield 'too old for the search in filters' => ['7.9.3', SystemCheckEnum::PROBLEM];
        yield 'another major' => ['8.15.0', SystemCheckEnum::PROBLEM];
        yield 'unknown' => [null, SystemCheckEnum::UNKNOWN];
    }

    #[DataProvider('versions')]
    public function testVersion(?string $version, SystemCheckEnum $expected): void
    {
        self::assertSame($expected, ElasticsearchVersion::result($version));
    }

    public function testDocumentsWaitWhileSomeMatch(): void
    {
        self::assertSame(SystemCheckEnum::ATTENTION, PendingImport::result(PendingImport::MATCHES_WAIT, 576, 903));
        self::assertSame(SystemCheckEnum::OK, PendingImport::result(PendingImport::MATCHES_WAIT, 0, 903));
    }

    public function testDocumentsWaitWhileNoneMatches(): void
    {
        self::assertSame(SystemCheckEnum::ATTENTION, PendingImport::result(PendingImport::NONE_WAIT, 0, 143));
        self::assertSame(SystemCheckEnum::OK, PendingImport::result(PendingImport::NONE_WAIT, 5, 143));
        // An empty index waits for nothing
        self::assertSame(SystemCheckEnum::OK, PendingImport::result(PendingImport::NONE_WAIT, 0, 0));
    }

    public function testEveryCheckIsComplete(): void
    {
        foreach (PendingImport::checks() as $check) {
            self::assertContains($check['index'], ['issue', 'review'], $check['label']);
            self::assertContains($check['kind'], [PendingImport::MATCHES_WAIT, PendingImport::NONE_WAIT], $check['label']);
            self::assertStringContainsString('--force', $check['command'], 'Without --force the import skips unchanged documents: ' . $check['label']);
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
