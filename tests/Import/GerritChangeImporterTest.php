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
    private MockApi $api;

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
        parse_str($this->api->request(0)->getUri()->getQuery(), $query);
        self::assertSame('project:Packages/TYPO3.CMS (change:95026)', $query['q']);
    }

    public function testTheQueryOfARetryIsTheSame(): void
    {
        $importer = $this->importer([new Response(429, ['Retry-After' => '2']), new Response(200, [], ")]}'\n[]")]);

        self::assertSame([], $importer->find('change:1', 1));
        // The query was lost on a retry once
        self::assertSame($this->api->request(0)->getUri()->getQuery(), $this->api->request(1)->getUri()->getQuery());
    }

    public function testTheRefreshOfAChangeAsksForTheDetailsOfTheImport(): void
    {
        $importer = $this->importer([new Response(200, [], ")]}'\n[]")]);

        $importer->refresh(81234);

        parse_str(str_replace('o=', 'o[]=', $this->api->request(0)->getUri()->getQuery()), $query);
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

        $noChain = static fn (): Response => new Response(200, [], ")]}'\n{\"changes\": []}");
        $document = $this->importer([$noChain()])->documents([$change])[81234];

        self::assertSame($message, $document['commit_message']);
        self::assertSame('The overlay first.', $document['commit_text']);
        self::assertSame([105123], $document['resolves']);
        self::assertSame([104000], $document['related']);
        self::assertSame(['main', '13.4'], $document['releases']);
        // Without an owner of Gerrit, the author of the commit
        self::assertSame(['Benni Mack', 'benni@example.org', null, null], [$document['owner'], $document['ownerEmail'], $document['owner_id'], $document['owner_username']]);
        self::assertNull(GerritChangeImporter::owner($document));

        $change['owner'] = ['_account_id' => 52917, 'name' => 'Ingo Hollmann', 'username' => 'ingoholl', 'email' => 'ingo@example.org'];
        $document = $this->importer([$noChain()])->documents([$change])[81234];

        // The owner, who uploaded it, not the author of the commit
        self::assertSame(['Ingo Hollmann', 'ingo@example.org', 52917, 'ingoholl'], [$document['owner'], $document['ownerEmail'], $document['owner_id'], $document['owner_username']]);
        $owner = GerritChangeImporter::owner($document);
        self::assertNotNull($owner);
        self::assertSame(['ingoholl', 52917, null, ['ingo@example.org'], md5('ingo@example.org')], [$owner->id(), $owner->gerritId, $owner->forgeId, $owner->emails, $owner->avatar()]);
    }

    public function testKeepsTheReviewLogWithTheAccountsButNotTheirAvatars(): void
    {
        $messages = GerritChangeImporter::messages([[
            'author' => [
                '_account_id' => 7,
                'name' => 'Jane Doe',
                'username' => 'jdoe',
                'email' => 'jane@example.org',
                'avatars' => [['url' => 'x']],
            ],
            'date' => '2026-11-19 07:48:47.000000000',
            '_revision_number' => 2,
            'tag' => 'autogenerated:gerrit:newPatchSet',
            'message' => 'Uploaded patch set 2.',
        ]]);

        self::assertSame([[
            'author' => ['id' => 7, 'name' => 'Jane Doe', 'username' => 'jdoe', 'email' => 'jane@example.org'],
            'date' => '2026/11/19 07:48:47',
            'patch_set' => 2,
            'tag' => 'autogenerated:gerrit:newPatchSet',
            'message' => 'Uploaded patch set 2.',
        ]], $messages);
    }

    public function testKeepsEachVoteButTheEmptyOnes(): void
    {
        $votes = GerritChangeImporter::singleVotes([
            'Code-Review' => ['all' => [
                ['_account_id' => 1, 'name' => 'A', 'value' => 2, 'date' => '2026-11-19 10:00:00.000000000'],
                ['_account_id' => 2, 'name' => 'B', 'value' => 0],
                ['_account_id' => 3, 'name' => 'C'],
            ]],
            'Verified' => ['all' => [['_account_id' => 49454, 'name' => 'core-ci', 'value' => 1]]],
            'Unknown' => ['all' => [['_account_id' => 4, 'value' => 1]]],
        ]);

        self::assertSame(
            [['Code-Review', 2, 1, '2026/11/19 10:00:00'], ['Verified', 1, 49454, null]],
            array_map(
                static fn (array $vote): array => [
                    $vote['label'],
                    $vote['value'],
                    $vote['account']['id'],
                    $vote['date'],
                ],
                $votes,
            ),
        );
    }

    public function testKeepsTheFilesWithTheirLinesByPath(): void
    {
        $files = GerritChangeImporter::fileDetails([
            'b.php' => ['lines_inserted' => 3, 'lines_deleted' => 1],
            'a.php' => ['status' => 'R', 'old_path' => 'old.php', 'lines_inserted' => 1],
            'logo.png' => ['status' => 'A', 'binary' => true],
        ]);

        self::assertSame(
            [
                [
                    'path' => 'a.php',
                    'status' => 'renamed',
                    'insertions' => 1,
                    'deletions' => 0,
                    'binary' => false,
                    'old_path' => 'old.php',
                ],
                [
                    'path' => 'b.php',
                    'status' => 'modified',
                    'insertions' => 3,
                    'deletions' => 1,
                    'binary' => false,
                    'old_path' => null,
                ],
                [
                    'path' => 'logo.png',
                    'status' => 'added',
                    'insertions' => 0,
                    'deletions' => 0,
                    'binary' => true,
                    'old_path' => null,
                ],
            ],
            $files,
        );
    }

    public function testTheWorstStateOfALabelCounts(): void
    {
        $states = GerritChangeImporter::labelStates([
            ['labels' => [['label' => 'Code-Review', 'status' => 'OK'], ['label' => 'Verified', 'status' => 'NEED']]],
            ['labels' => [['label' => 'Code-Review', 'status' => 'REJECT'], ['label' => 'Other', 'status' => 'NEED']]],
        ]);

        self::assertSame(['Code-Review' => 'REJECT', 'Verified' => 'NEED'], $states);
    }

    public function testTheConflictsAreThoseOfTheCurrentPatchSet(): void
    {
        $messages = [
            [
                '_revision_number' => 3,
                'message' => "Rebased.\n\nThe following files contain Git conflicts:\n* old.php\n",
            ],
            [
                '_revision_number' => 4,
                'message' => "Patch Set 4: Cherry Picked\n\nThe following files contain Git conflicts:\n"
                    . "* composer.json\n* typo3/sysext/core/Classes/Cache.php\n",
            ],
        ];

        self::assertSame(
            ['composer.json', 'typo3/sysext/core/Classes/Cache.php'],
            GerritChangeImporter::conflicts($messages, 4),
        );
        self::assertSame([], GerritChangeImporter::conflicts($messages, 5));
    }

    public function testAnOpenChangeHasItsChainAClosedOneNone(): void
    {
        $link = static fn (int $number, int $patchSet, int $current, string $subject): array => [
            '_change_number' => $number,
            '_revision_number' => $patchSet,
            '_current_revision_number' => $current,
            'status' => 'NEW',
            'commit' => ['subject' => $subject],
        ];
        $related = ")]}'\n" . json_encode(['changes' => [$link(2, 1, 2, 'Above'), $link(1, 3, 3, 'This')]]);
        $change = [
            '_number' => 1,
            'status' => 'NEW',
            'change_id' => 'I1',
            'subject' => 'This',
            'branch' => 'main',
            'insertions' => 1,
            'deletions' => 0,
            'created' => '2026-11-01 10:00:00.000000000',
            'updated' => '2026-11-01 10:00:00.000000000',
            'labels' => [],
            'current_revision' => 'abc',
            'revisions' => ['abc' => [
                '_number' => 3,
                'files' => [],
                'commit' => ['message' => 'This', 'author' => ['name' => 'A', 'email' => 'a@example.org']],
            ]],
        ];

        $importer = $this->importer([new Response(200, [], $related)]);
        $chain = $importer->documents([$change])[1]['chain'];

        self::assertSame('/changes/1/revisions/current/related', $this->api->request(0)->getUri()->getPath());
        self::assertSame([
            ['number' => 2, 'patch_set' => 1, 'current_patch_set' => 2, 'status' => 'NEW', 'subject' => 'Above'],
            ['number' => 1, 'patch_set' => 3, 'current_patch_set' => 3, 'status' => 'NEW', 'subject' => 'This'],
        ], $chain);
        // A merged change asks for nothing
        self::assertSame([], $this->importer([])->documents([['status' => 'MERGED'] + $change])[1]['chain']);
    }

    public function testThePeopleOfAChangeAreItsOwnerAndItsReviewersButTheCi(): void
    {
        $account = static fn (int $id): array => [
            'id' => $id,
            'name' => 'P' . $id,
            'username' => 'p' . $id,
            'email' => 'p' . $id . '@example.org',
        ];
        $users = GerritChangeImporter::accounts([
            'owner_id' => 1,
            'owner' => 'P1',
            'owner_username' => 'p1',
            'ownerEmail' => 'p1@example.org',
            'votes' => [['account' => $account(2)], ['account' => $account(49454)]],
            'messages' => [['author' => $account(3)]],
        ]);

        self::assertSame([1, 2, 3], array_map(static fn ($user): ?int => $user->gerritId, $users));
        self::assertSame(['p2@example.org'], $users[1]->emails);
    }

    /**
     * @param list<Response> $responses
     */
    private function importer(array $responses, ?ProjectChanges $projectChanges = null): GerritChangeImporter
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());
        $api = new MockApi($responses, $recorder, 'Gerrit');
        $this->api = $api;

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
