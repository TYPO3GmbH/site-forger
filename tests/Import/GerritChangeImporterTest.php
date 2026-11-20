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
use App\Import\ChangeRefreshResult;
use App\Import\GerritChangeImporter;
use App\Import\ProjectChanges;
use App\People\UserDirectory;
use App\Repository\UserRepository;
use App\Tests\Http\MockApi;
use Doctrine\DBAL\Connection;
use Elastica\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GerritChangeImporterTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $requests = [];

    public function testSumsAndTheLowestAndHighestSingleVote(): void
    {
        $labels = GerritChangeImporter::labels([
            'Code-Review' => ['all' => [
                ['_account_id' => 1, 'value' => 2],
                ['_account_id' => 2, 'value' => 1],
                ['_account_id' => 3, 'value' => 0],
            ]],
            'Verified' => ['all' => [
                // The CI is no reviewer
                ['_account_id' => 49454, 'value' => -2],
                ['_account_id' => 4, 'value' => -1],
                ['_account_id' => 5, 'value' => 1],
            ]],
        ]);

        self::assertSame([
            'positive_reviews' => 3,
            'negative_reviews' => 0,
            'lowest_review_vote' => 0,
            'highest_review_vote' => 2,
            'positive_verifications' => 1,
            'negative_verifications' => 1,
            'lowest_verification_vote' => -1,
            'highest_verification_vote' => 1,
        ], $labels);
    }

    public function testWithoutVotes(): void
    {
        $labels = GerritChangeImporter::labels(['Code-Review' => [], 'Verified' => ['all' => [['_account_id' => 1]]]]);

        self::assertSame(0, $labels['lowest_review_vote']);
        self::assertSame(0, $labels['highest_verification_vote']);
    }

    public function testGerritDatesInTheFormatOfTheIndex(): void
    {
        self::assertSame('2026/11/19 17:42:30', GerritChangeImporter::gerritDate('2026-11-19 17:42:30.000000000'));
    }

    public function testARefreshedChangeGerritDoesNotShowAnyMoreIsRemoved(): void
    {
        $projectChanges = $this->createMock(ProjectChanges::class);
        $projectChanges->expects(self::once())->method('remove')->with([95026])->willReturn(1);

        self::assertSame(ChangeRefreshResult::REMOVED, $this->importer([new Response(200, [], ")]}'\n[]")], $projectChanges)->refresh(95026));
    }

    public function testARefreshedChangeNeitherGerritNorTheIndexHasIsNotFound(): void
    {
        $projectChanges = $this->createStub(ProjectChanges::class);
        $projectChanges->method('remove')->willReturn(0);

        self::assertSame(ChangeRefreshResult::NOT_FOUND, $this->importer([new Response(200, [], ")]}'\n[]")], $projectChanges)->refresh(95026));
    }

    public function testARefreshThatFailsRemovesNothing(): void
    {
        $projectChanges = $this->createMock(ProjectChanges::class);
        $projectChanges->expects(self::never())->method('remove');

        $this->expectException(\RuntimeException::class);
        $this->importer([new Response(404)], $projectChanges)->refresh(95026);
    }

    public function testRefreshAsksForTheChangeInTheProjectOnly(): void
    {
        $importer = $this->importer([new Response(200, [], ")]}'\n[]")]);

        self::assertSame(ChangeRefreshResult::NOT_FOUND, $importer->refresh(95026));
        parse_str($this->requests[0]['request']->getUri()->getQuery(), $query);
        self::assertSame('project:Packages/TYPO3.CMS (change:95026)', $query['q']);
    }

    public function testTheQueryOfARetryIsTheSame(): void
    {
        $importer = $this->importer([new Response(429, ['Retry-After' => '2']), new Response(200, [], ")]}'\n[]")]);

        self::assertSame([], $importer->find('change:1', 1));
        // The query was lost on a retry once
        self::assertSame($this->requests[0]['request']->getUri()->getQuery(), $this->requests[1]['request']->getUri()->getQuery());
    }

    public function testTheRefreshOfAChangeAsksForTheDetailsOfTheImport(): void
    {
        $importer = $this->importer([new Response(200, [], ")]}'\n[]")]);

        $importer->refresh(81234);

        parse_str(str_replace('o=', 'o[]=', $this->requests[0]['request']->getUri()->getQuery()), $query);
        // The same details as the full import and the delta, see GerritImport
        self::assertSame(GerritChangeImporter::DETAIL_OPTIONS, $query['o']);
        // Only the current patch set
        self::assertNotContains('ALL_REVISIONS', GerritChangeImporter::DETAIL_OPTIONS);
        self::assertNotContains('ALL_FILES', GerritChangeImporter::DETAIL_OPTIONS);
    }

    public function testTheDocumentKeepsTheCommitMessageAndTheIssuesOfItsFooter(): void
    {
        $message = "[BUGFIX] Show the translated record\n\nThe overlay first.\n\nResolves: #105123\nRelated: #104000\nReleases: main, 13.4\nChange-Id: I0123";
        $change = [
            '_number' => 81234,
            'change_id' => 'I0123',
            'status' => 'NEW',
            'subject' => '[BUGFIX] Show the translated record',
            'branch' => 'master',
            'mergeable' => true,
            'insertions' => 12,
            'deletions' => 3,
            'created' => '2026-11-01 10:00:00.000000000',
            'updated' => '2026-11-02 10:00:00.000000000',
            'labels' => [],
            'messages' => [],
            'current_revision' => 'abc',
            'revisions' => ['abc' => [
                '_number' => 2,
                'files' => ['typo3/sysext/core/Classes/Cache.php' => []],
                'commit' => ['message' => $message, 'author' => ['name' => 'Benni Mack', 'email' => 'benni@example.org']],
            ]],
        ];

        $document = $this->importer([])->documents([$change])[81234];

        self::assertSame($message, $document['commit_message']);
        self::assertSame('The overlay first.', $document['commit_text']);
        self::assertSame([105123], $document['resolves']);
        self::assertSame([104000], $document['related']);
        self::assertSame(['main', '13.4'], $document['releases']);
        // Without an owner of Gerrit, the author of the commit
        self::assertSame(['Benni Mack', 'benni@example.org', null, null], [$document['owner'], $document['ownerEmail'], $document['owner_id'], $document['owner_username']]);
        self::assertNull(GerritChangeImporter::owner($document));

        $change['owner'] = ['_account_id' => 52917, 'name' => 'Ingo Hollmann', 'username' => 'ingoholl', 'email' => 'ingo@example.org'];
        $document = $this->importer([])->documents([$change])[81234];

        // The owner, who uploaded it, not the author of the commit
        self::assertSame(['Ingo Hollmann', 'ingo@example.org', 52917, 'ingoholl'], [$document['owner'], $document['ownerEmail'], $document['owner_id'], $document['owner_username']]);
        $owner = GerritChangeImporter::owner($document);
        self::assertNotNull($owner);
        self::assertSame(['ingoholl', 52917, null, ['ingo@example.org'], md5('ingo@example.org')], [$owner->id(), $owner->gerritId, $owner->forgeId, $owner->emails, $owner->avatar()]);
    }

    /**
     * @param list<Response> $responses
     */
    private function importer(array $responses, ?ProjectChanges $projectChanges = null): GerritChangeImporter
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());
        $api = new MockApi($responses, $recorder, 'Gerrit');
        $this->requests = &$api->requests;

        return new GerritChangeImporter(
            new Indices(new Client()),
            $api->client,
            $recorder,
            $projectChanges ?? $this->createStub(ProjectChanges::class),
            new UserRepository(
                new Indices(new Client()),
                new UserDirectory(new Indices(new Client()), new ArrayAdapter(), new NullLogger()),
                new NullLogger(),
            ),
        );
    }
}
