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
use Redmine\Api\Issue;
use Redmine\Client\Client;
use Redmine\Http\HttpClient;
use Redmine\Http\HttpFactory;
use Redmine\Http\Request;
use Redmine\Http\Response;
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

    public function testDocumentHasTheDuplicatesFromBothSidesOfTheRelations(): void
    {
        $document = ForgeIssueImporter::document([
            'id' => 91089,
            'created_on' => '2026-11-01T10:00:00Z',
            'updated_on' => '2026-11-01T10:00:00Z',
            'relations' => [
                ['id' => 1, 'issue_id' => 91089, 'issue_to_id' => 80001, 'relation_type' => 'duplicates', 'delay' => null],
                ['id' => 2, 'issue_id' => 91200, 'issue_to_id' => 91089, 'relation_type' => 'duplicates', 'delay' => null],
                ['id' => 3, 'issue_id' => 91089, 'issue_to_id' => 80002, 'relation_type' => 'relates', 'delay' => null],
            ],
        ]);

        self::assertSame([80001], $document['duplicates']);
        self::assertSame([91200], $document['duplicated_by']);
        self::assertArrayNotHasKey('relations', $document);
    }

    public function testDocumentWithoutRelationsHasNoDuplicates(): void
    {
        $document = ForgeIssueImporter::document(['id' => 1, 'created_on' => '2026-11-01T10:00:00Z', 'updated_on' => '2026-11-01T10:00:00Z']);

        self::assertSame([], $document['duplicates']);
        self::assertSame([], $document['duplicated_by']);
    }

    public function testRateLimitIsAnErrorAtOnceWithoutAHandler(): void
    {
        $calls = 0;
        $importer = $this->importer([429]);

        try {
            $importer->call('issue', static function (Issue $issues) use (&$calls): mixed {
                ++$calls;

                return $issues->show(91089);
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

        self::assertSame(['issue' => ['id' => 91089]], $importer->call('issue', static fn (Issue $issues): mixed => $issues->show(91089)));
        self::assertSame([1, 2], $attempts);
    }

    public function testHandlerGivesUpAfterTheRetries(): void
    {
        $importer = $this->importer([429, 429, 429]);
        $importer->onRateLimit(static function (int $attempt): void {
        }, 2);

        $this->expectExceptionCode(429);
        $importer->call('issue', static fn (Issue $issues): mixed => $issues->show(91089));
    }

    public function testErrorsOfForgeOtherThanGoneAreThrownByRefresh(): void
    {
        $importer = $this->importer([500]);

        // The index is not touched: the issue keeps its state there
        $this->expectExceptionCode(500);
        $importer->refresh(91089);
    }

    /**
     * @param list<int> $statuses the status of each request, in order
     */
    private function importer(array $statuses): ForgeIssueImporter
    {
        // Forge answers each request with the next status, the issue with a success
        $http = new class($statuses) implements HttpClient {
            /** @param list<int> $statuses */
            public function __construct(private array $statuses)
            {
            }

            public function request(Request $request): Response
            {
                $status = array_shift($this->statuses) ?? 200;

                return HttpFactory::makeResponse($status, 'application/json', $status < 400 ? '{"issue":{"id":91089}}' : '');
            }
        };
        $client = $this->createStub(Client::class);
        $client->method('getApi')->willReturn(Issue::fromHttpClient($http));

        return new ForgeIssueImporter(
            $client,
            new ArrayAdapter(),
            new ProjectIssues(27),
            new CommandRunRecorder($this->createStub(Connection::class), new NullLogger()),
        );
    }
}
