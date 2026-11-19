<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Service;

use App\Elastic\ProjectIssues;
use App\Service\CommandRunRecorder;
use App\Service\ForgeIssueImporter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Redmine\Client\Client;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ForgeIssueImporterTest extends TestCase
{
    public function testDocumentHasTheCustomFieldsAsFieldsAndTheDatesOfTheIndex(): void
    {
        $document = ForgeIssueImporter::document([
            'id' => 91089,
            'custom_fields' => [
                ['id' => 3, 'value' => 'cache, performance'],
                ['id' => 4, 'value' => '13'],
                ['id' => 5, 'value' => '8.3'],
                ['id' => 8, 'value' => 'easy'],
                ['id' => 15, 'value' => '1'],
                ['id' => 18, 'value' => 'Backend'],
                ['id' => 99, 'value' => 'ignored'],
            ],
            'created_on' => '2026-11-01T10:00:00Z',
            'updated_on' => '2026-11-02T11:30:00Z',
            'start_date' => '2026-11-01',
            'due_date' => null,
        ]);

        self::assertSame(['cache', 'performance'], $document['tags']);
        self::assertSame('13', $document['typo3_version']);
        self::assertSame('8.3', $document['php_version']);
        self::assertSame('easy', $document['complexity']);
        self::assertTrue($document['isregression']);
        self::assertSame(['name' => 'Backend'], $document['focus']);
        self::assertSame('2026/11/01 10:00:00', $document['created_on']);
        self::assertSame('2026/11/02 11:30:00', $document['updated_on']);
        self::assertArrayNotHasKey('start_date', $document);
        self::assertArrayNotHasKey('due_date', $document);
    }

    public function testDocumentWithoutCustomFields(): void
    {
        $document = ForgeIssueImporter::document(['id' => 1, 'created_on' => '2026-11-01T10:00:00Z', 'updated_on' => '2026-11-01T10:00:00Z']);

        self::assertArrayNotHasKey('tags', $document);
        self::assertArrayNotHasKey('isregression', $document);
    }

    public function testRateLimitIsAnErrorAtOnceWithoutAHandler(): void
    {
        $calls = 0;
        $importer = $this->importer([429]);

        try {
            $importer->call(static function () use (&$calls): array {
                ++$calls;

                return [];
            });
            self::fail('A rate limit is an error');
        } catch (\RuntimeException $e) {
            self::assertSame(429, $e->getCode());
        }
        self::assertSame(1, $calls);
    }

    public function testRateLimitIsWaitedForWithAHandler(): void
    {
        $importer = $this->importer([429, 429, 200]);
        $attempts = [];
        $importer->onRateLimit(static function (int $attempt) use (&$attempts): void {
            $attempts[] = $attempt;
        }, 6);

        self::assertSame(['issue' => []], $importer->call(static fn (): array => ['issue' => []]));
        self::assertSame([1, 2], $attempts);
    }

    public function testHandlerGivesUpAfterTheRetries(): void
    {
        $importer = $this->importer([429, 429, 429]);
        $importer->onRateLimit(static function (int $attempt): void {
        }, 2);

        $this->expectExceptionCode(429);
        $importer->call(static fn (): array => []);
    }

    /**
     * @param list<int> $statuses the status of each request, in order
     */
    private function importer(array $statuses): ForgeIssueImporter
    {
        $client = $this->createStub(Client::class);
        $client->method('getLastResponseStatusCode')->willReturnOnConsecutiveCalls(...$statuses);
        $client->method('getApi')->willReturn($this->createStub(\Redmine\Api\Issue::class));

        return new ForgeIssueImporter(
            $client,
            new ArrayAdapter(),
            new ProjectIssues(27),
            new CommandRunRecorder($this->createStub(Connection::class), new NullLogger()),
        );
    }
}
