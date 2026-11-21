<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\CommandRun\CommandRunRecorder;
use App\Elastic\Indices;
use App\Http\ApiFailure;
use App\Import\ForgeIssueImporter;
use App\Import\ForgeIssueList;
use App\Import\ProjectIssues;
use App\People\UserDirectory;
use App\Repository\UserRepository;
use App\Tests\Http\MockApi;
use Doctrine\DBAL\Connection;
use Elastica\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

    public function testDocumentHasTheOtherRelationsSeenFromTheIssue(): void
    {
        $document = ForgeIssueImporter::document([
            'id' => 91089,
            'created_on' => '2026-11-01T10:00:00Z',
            'updated_on' => '2026-11-01T10:00:00Z',
            'parent' => ['id' => 90000],
            'relations' => [
                ['id' => 1, 'issue_id' => 91089, 'issue_to_id' => 80001, 'relation_type' => 'duplicates'],
                ['id' => 2, 'issue_id' => 91089, 'issue_to_id' => 80002, 'relation_type' => 'relates'],
                ['id' => 3, 'issue_id' => 91089, 'issue_to_id' => 80003, 'relation_type' => 'blocks'],
                // Named from the other side: 80004 blocks this issue, 80005 precedes it
                ['id' => 4, 'issue_id' => 80004, 'issue_to_id' => 91089, 'relation_type' => 'blocks'],
                ['id' => 5, 'issue_id' => 80005, 'issue_to_id' => 91089, 'relation_type' => 'precedes'],
                ['id' => 6, 'issue_id' => 80006, 'issue_to_id' => 91089, 'relation_type' => 'copied_to'],
                ['id' => 7, 'issue_id' => 91089, 'issue_to_id' => 80007, 'relation_type' => 'unknown'],
            ],
        ]);

        self::assertSame([
            ['type' => 'relates', 'issue' => 80002],
            ['type' => 'blocks', 'issue' => 80003],
            ['type' => 'blocked', 'issue' => 80004],
            ['type' => 'follows', 'issue' => 80005],
            ['type' => 'copied_from', 'issue' => 80006],
        ], $document['issue_relations']);
        self::assertSame([80001], $document['duplicates']);
        self::assertSame(['id' => 90000], $document['parent']);
    }

    public function testDocumentWithoutRelationsHasNoDuplicates(): void
    {
        $document = ForgeIssueImporter::document(['id' => 1, 'created_on' => '2026-11-01T10:00:00Z', 'updated_on' => '2026-11-01T10:00:00Z']);

        self::assertSame([], $document['duplicates']);
        self::assertSame([], $document['duplicated_by']);
        self::assertSame([], $document['issue_relations']);
    }

    public function testErrorsOfForgeOtherThanNotFoundAreThrownByRefresh(): void
    {
        // A page tries twice again, then gives up
        $importer = $this->importer([new Response(500), new Response(500), new Response(500)]);

        // The index is not touched: the issue keeps its state there
        $this->expectExceptionObject(ApiFailure::status('Forge', 500));
        $importer->refresh(91089);
    }

    public function testAnAnswerWithoutAListOfIssuesIsAFailure(): void
    {
        $this->expectExceptionMessage('Forge answered without a list of issues: {"errors":[]}');
        $this->importer([new Response(200, [], '{"errors":[]}')])->refresh(91089);
    }

    /**
     * @param list<Response> $responses the answers of Forge, in order
     */
    private function importer(array $responses): ForgeIssueImporter
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());

        $forge = new MockApi($responses, $recorder)->client;

        return new ForgeIssueImporter(
            new Indices(new Client()),
            $forge,
            new ForgeIssueList($forge, 27),
            new UserRepository(
                new Indices(new Client()),
                new UserDirectory(new Indices(new Client()), new ArrayAdapter(), new NullLogger()),
                new NullLogger(),
            ),
            new ProjectIssues(new Indices(new Client()), 27),
            $recorder,
        );
    }
}
