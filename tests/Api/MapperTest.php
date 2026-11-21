<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Api;

use App\Api\ChangeMapper;
use App\Api\IssueMapper;
use App\Document\Issue;
use App\Duplicates\DuplicateGroups;
use App\Duplicates\DuplicateHit;
use App\Elastic\SearchResult;
use PHPUnit\Framework\TestCase;

final class MapperTest extends TestCase
{
    public function testAnIssueHasItsFieldsAndItsRelations(): void
    {
        $issue = self::issueDetail();

        self::assertSame(105403, $issue['id']);
        self::assertSame('Bug', $issue['tracker']);
        self::assertFalse($issue['closed']);
        self::assertSame(['id' => 36415, 'name' => 'Jane Doe'], $issue['author']);
        self::assertSame('2026-11-20T23:40:03Z', $issue['createdOn']);
        self::assertSame('https://forge.typo3.org/issues/105403', $issue['url']);
        self::assertSame('https://forger.test/issue_show/105403', $issue['forgerUrl']);
        self::assertSame(105000, $issue['parent']);
        self::assertSame([105500], $issue['subtasks']);
        self::assertSame([['type' => 'blocks', 'issue' => 101234]], $issue['relations']);
        self::assertSame([101234], $issue['duplicates']);
        self::assertSame('linked', $issue['changes'][0]['relation']);
        self::assertSame('ready', $issue['changes'][0]['state']);
    }

    public function testLeavesOutTheRelatedIssuesForgerDoesNotHave(): void
    {
        // The token of the import sees private issues in the relations, the index has none of them
        $issue = new IssueMapper(ApiFixtures::urls())->detail(
            ApiFixtures::issue(),
            ApiFixtures::changesOfTheIssue(),
            ApiFixtures::subtasks(),
            [],
        );

        self::assertNull($issue->parent);
        self::assertSame([], $issue->relations);
        self::assertSame([], $issue->duplicates);
    }

    public function testListsTheMarkedDuplicatesBeforeTheSimilarIssues(): void
    {
        $marked = new DuplicateHit(Issue::fromDocument(['id' => 101234, 'subject' => 'Marked']), null);
        $similar = DuplicateHit::checked(Issue::fromDocument(['id' => 104000, 'subject' => 'Similar']), 70, 60);
        $groups = new DuplicateGroups([$marked->withRelation(DuplicateGroups::DUPLICATES)], [$similar]);

        $list = self::decoded(new IssueMapper(ApiFixtures::urls())->duplicates(ApiFixtures::issue(), $groups));

        self::assertSame(105403, $list['issue']);
        self::assertSame(DuplicateGroups::MIN_SIMILARITY, $list['minimumSimilarity']);
        self::assertSame(
            [[101234, null, 'duplicates'], [104000, 65, null]],
            array_map(
                static fn (array $item): array => [$item['issue']['id'], $item['similarity'], $item['relation']],
                $list['items'],
            ),
        );
    }

    public function testAChangeHasItsFieldsAndItsIssues(): void
    {
        $change = self::changeDetail();

        self::assertSame(97865, $change['number']);
        self::assertSame('ready', $change['state']);
        self::assertSame('crop', $change['topic']);
        self::assertTrue($change['mergeable']);
        self::assertSame(['codeReview' => 1, 'verified' => -1], $change['votes']);
        self::assertSame(['id' => 14410, 'name' => 'Jane Doe', 'username' => 'jdoe'], $change['owner']);
        self::assertSame('resolves', $change['issues'][0]['relation']);
        self::assertSame('Under Review', $change['issues'][0]['status']);
        self::assertSame(['id' => 99999, 'relation' => 'related', 'subject' => null, 'status' => null], array_slice(
            $change['issues'][1],
            0,
            4,
        ));
    }

    public function testNoEmailAddressLeavesForger(): void
    {
        $json = (string) json_encode([self::issueDetail(), self::changeDetail()]);

        self::assertStringNotContainsString(ApiFixtures::EMAIL, $json);
        self::assertStringNotContainsStringIgnoringCase('email', $json);
    }

    /**
     * @return array<string, mixed>
     */
    private static function issueDetail(): array
    {
        $issue = new IssueMapper(ApiFixtures::urls())->detail(
            ApiFixtures::issue(),
            ApiFixtures::changesOfTheIssue(),
            ApiFixtures::subtasks(),
            ApiFixtures::indexedRelatedIssues(),
        );

        return self::decoded($issue);
    }

    /**
     * @return array<string, mixed>
     */
    private static function changeDetail(): array
    {
        $change = new ChangeMapper(ApiFixtures::urls())->detail(
            ApiFixtures::change(),
            ApiFixtures::issuesOfTheChange(),
        );

        return self::decoded($change);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(object $model): array
    {
        return (array) json_decode((string) json_encode($model), true);
    }

    public function testCountsHowTheIssuesSpread(): void
    {
        $found = SearchResult::ofDocuments([], 0, [
            'status' => ['buckets' => [['key' => 'New', 'doc_count' => 3]]],
            'category' => ['buckets' => [['key' => 'none', 'doc_count' => 2]]],
            'createdYear' => ['buckets' => [['key' => 1356998400000, 'key_as_string' => '2013', 'doc_count' => 1]]],
        ]);

        $breakdown = new IssueMapper(ApiFixtures::urls())->breakdown($found);

        self::assertSame([['name' => 'New', 'count' => 3]], self::decoded($breakdown)['status']);
        self::assertSame([['name' => 'none', 'count' => 2]], self::decoded($breakdown)['category']);
        self::assertSame([['name' => '2013', 'count' => 1]], self::decoded($breakdown)['createdYear']);
        self::assertSame([], $breakdown->tracker);
    }

    public function testLeavesOutAUsernameThatIsAnEmailAddress(): void
    {
        self::assertSame('jdoe', ChangeMapper::publicUsername('jdoe'));
        self::assertNull(ChangeMapper::publicUsername(ApiFixtures::EMAIL));
    }
}
