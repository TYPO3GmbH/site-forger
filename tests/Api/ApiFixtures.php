<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Api;

use App\Document\Change;
use App\Document\Issue;
use App\Document\RelatedChange;
use App\Document\RelatedIssue;
use App\Document\Relations;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * An issue and a change with every field the API returns, each with an email address the API must leave out.
 */
final class ApiFixtures
{
    public const string EMAIL = 'jane@example.com';

    public static function issue(): Issue
    {
        return Issue::fromDocument([
            'id' => 105403,
            'subject' => 'Image crop is ignored',
            'description' => 'The crop is ignored.',
            'status' => ['id' => 9, 'name' => 'Under Review'],
            'tracker' => ['id' => 1, 'name' => 'Bug'],
            'priority' => ['id' => 4, 'name' => 'Should have'],
            'category' => ['id' => 1, 'name' => 'Backend User Interface'],
            'fixed_version' => ['id' => 1, 'name' => '14.0'],
            'author' => ['id' => 36415, 'name' => 'Jane Doe', 'email' => self::EMAIL],
            'assigned_to' => ['id' => 36416, 'name' => 'John Doe', 'email' => self::EMAIL],
            'created_on' => '2026/11/20 23:40:03',
            'updated_on' => '2026/11/21 08:00:00',
            'tags' => ['image'],
            'typo3_version' => '13',
            'php_version' => '8.3',
            'parent' => ['id' => 105000],
            'duplicates' => [101234],
            'duplicated_by' => [],
            'issue_relations' => [['type' => 'relates', 'issue' => 104990], ['type' => 'blocks', 'issue' => 101234]],
            'journals' => [
                self::journal(36415, 'Jane Doe', '2026-11-20T10:00:00Z', 'Confirmed.'),
                self::journal(36415, 'Jane Doe', '2026-11-20T11:00:00Z', ''),
                self::journal(1, 'Gerrit Code Review', '2026-11-21T08:00:00Z', 'Patch set 1 was pushed.'),
                self::journal(2, 'John Doe', '2026-11-21T09:00:00Z', 'Hidden.') + ['private_notes' => true],
            ],
        ]);
    }

    public static function change(): Change
    {
        return Change::fromDocument([
            'id' => 97865,
            'change_id' => 'I13752ccd5cd00954877461c2622c0986c1fc8c6d',
            'subject' => '[BUGFIX] Respect the image crop',
            'reviewstatus' => 'NEW',
            'branch' => 'main',
            'topic' => 'crop',
            'reviewtags' => ['BUGFIX'],
            'mergeable' => 'yes',
            'insertions' => 21,
            'deletions' => 6,
            'patchsets' => 2,
            'lowest_review_vote' => 0,
            'highest_review_vote' => 1,
            'lowest_verification_vote' => -1,
            'highest_verification_vote' => 2,
            'created_on' => '2026/11/20 23:18:19',
            'updated_on' => '2026/11/20 23:48:13',
            'owner' => 'Jane Doe',
            'owner_id' => 14410,
            'owner_username' => 'jdoe',
            'ownerEmail' => self::EMAIL,
            'files' => ['typo3/sysext/backend/Classes/Preview/StandardContentPreviewRenderer.php'],
            'commit_message' => "[BUGFIX] Respect the image crop\n\nResolves: #105403\nReleases: main",
            'releases' => ['main'],
            'resolves' => [105403],
        ]);
    }

    /**
     * @return ArrayCollection<int, RelatedChange>
     */
    public static function changesOfTheIssue(): ArrayCollection
    {
        return new ArrayCollection([new RelatedChange(self::change(), Relations::LINKED)]);
    }

    /**
     * The related issues Forger has: the parent, the duplicate and the related one of issue(), but for 104990.
     *
     * @return array<int, true>
     */
    public static function indexedRelatedIssues(): array
    {
        return [105000 => true, 101234 => true];
    }

    /**
     * @return ArrayCollection<int, Issue>
     */
    public static function subtasks(): ArrayCollection
    {
        return new ArrayCollection([Issue::fromDocument(['id' => 105500, 'subject' => 'A subtask'])]);
    }

    /**
     * @return ArrayCollection<int, RelatedIssue>
     */
    public static function issuesOfTheChange(): ArrayCollection
    {
        return new ArrayCollection([
            new RelatedIssue(105403, 'Resolves', self::issue()),
            new RelatedIssue(99999, 'Related', null),
        ]);
    }

    public static function urls(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(
                string $name,
                array $parameters = [],
                int $referenceType = self::ABSOLUTE_PATH,
            ): string {
                return 'https://forger.test/' . $name . '/' . implode('/', $parameters);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };
    }

    /**
     * A journal of an issue as Forge sends it.
     *
     * @return array<string, mixed>
     */
    private static function journal(int $user, string $name, string $createdOn, string $notes): array
    {
        return ['user' => ['id' => $user, 'name' => $name], 'created_on' => $createdOn, 'notes' => $notes];
    }
}
