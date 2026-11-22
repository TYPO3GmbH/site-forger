<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Document\Change;
use App\Document\Issue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Documents imported before a field was added must still render: an index is filled again by a full import only.
 * The templates run with strict variables here, so a field they read without a default fails the test.
 */
final class CardsWithoutNewFieldsTest extends KernelTestCase
{
    use KnowsNoPeople;

    /**
     * A change as the import stored it before "ownerEmail" and the tag "!!!".
     */
    private const array OLD_CHANGE = [
        'id' => 12345,
        'change_id' => 'I0123456789abcdef',
        'reviewstatus' => 'NEW',
        'subject' => '[!!!][FEATURE] Remove the old API',
        'branch' => 'main',
        'topic' => 'no topic',
        'releases' => ['main'],
        'mergeable' => 'yes',
        'affected_files' => 1,
        'patchsets' => 1,
        'positive_reviews' => 0,
        'negative_reviews' => 0,
        'positive_verifications' => 0,
        'negative_verifications' => 0,
        'created_on' => '2026/11/01 10:00:00',
        'updated_on' => '2026/11/01 10:00:00',
        'owner' => 'Benni Mack',
        'work_in_progress' => false,
    ];

    /**
     * An issue as the import stored it before the email of the assignee.
     */
    private const array OLD_ISSUE = [
        'id' => 91089,
        'subject' => 'Something is broken',
        'description' => 'Steps to reproduce',
        'tracker' => ['id' => 1, 'name' => 'Bug'],
        'status' => ['id' => 1, 'name' => 'New'],
        'author' => ['id' => 1, 'name' => 'Benni Mack'],
        'assigned_to' => ['id' => 2, 'name' => 'Benjamin Kott'],
        'custom_fields' => [],
        'created_on' => '2026/11/01 10:00:00',
        'updated_on' => '2026/11/01 10:00:00',
    ];

    /**
     * The cache of the avatars, see App\People\UserDirectory.
     */
    private const string AVATARS_CACHE_KEY = 'user_avatars';

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
        self::assertTrue($this->twig->isStrictVariables());
        // The avatars of the issues come from the index "user", here from its cache
        $this->avatars([]);
        self::knowNoPeople();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('cache.app')->delete(self::AVATARS_CACHE_KEY);
        parent::tearDown();
    }

    public function testChangeWithoutNewFields(): void
    {
        $change = self::OLD_CHANGE;

        $card = $this->twig->render('_partials/review_card.html.twig', ['card' => Change::fromDocument($change)]);
        $full = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument($change), 'size' => 'l']);

        foreach ([$card, $full] as $html) {
            self::assertStringContainsString('Benni Mack', $html);
            self::assertStringNotContainsString('review-breaking', $html);
        }
        // The placeholder of Gravatar without an address, every person alike
        self::assertStringContainsString('https://www.gravatar.com/avatar/' . md5('') . '?s=20&amp;d=mp', $card);
        self::assertStringContainsString('https://www.gravatar.com/avatar/' . md5('') . '?s=40&amp;d=mp', $full);
    }

    public function testOnlyChangesLyingStillShowTheirActivity(): void
    {
        $row = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument(self::OLD_CHANGE), 'size' => 'l']);
        self::assertStringContainsString('status-pill-activity-', $row);

        $change = ['updated_on' => date('Y/m/d H:i:s'), 'created_on' => date('Y/m/d H:i:s')] + self::OLD_CHANGE;
        $row = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument($change), 'size' => 'l']);
        self::assertStringNotContainsString('status-pill-activity-', $row);
    }

    public function testRowsNameTheStateOnlyWhenNotReady(): void
    {
        $row = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument(self::OLD_CHANGE), 'size' => 'l']);
        self::assertStringNotContainsString('status-pill-ready', $row);

        foreach (['wip' => ['work_in_progress' => true], 'merged' => ['reviewstatus' => 'MERGED']] as $state => $fields) {
            $row = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument($fields + self::OLD_CHANGE), 'size' => 'l']);
            self::assertStringContainsString('status-pill-' . $state . '"', $row);
        }
    }

    public function testRowShowsBranchAndReleasesTogether(): void
    {
        $change = self::OLD_CHANGE;
        $row = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument($change), 'size' => 'l']);
        // The branch is in the releases of old documents, it shows once
        self::assertStringContainsString('<span title="Branch">→ main</span>', $row);
        self::assertStringNotContainsString('class="tag-label" title="Branch"', $row);

        $change['releases'] = ['main', '14.3'];
        $row = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument($change), 'size' => 'l']);
        self::assertStringContainsString('<span title="Branch and target releases">→ main, 14.3</span>', $row);
    }

    public function testChangeWithTagsAndEmail(): void
    {
        $change = self::OLD_CHANGE + ['reviewtags' => ['!!!', 'FEATURE'], 'ownerEmail' => 'benni@example.org'];

        $html = $this->twig->render('_partials/review_card.html.twig', ['card' => Change::fromDocument($change)]);

        self::assertStringContainsString('review-type-feature', $html);
        self::assertStringContainsString('review-breaking', $html);
        self::assertStringContainsString('class="avatar"', $html);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function oldIssues(): iterable
    {
        yield 'assignee without email' => [self::OLD_ISSUE];
        $issue = self::OLD_ISSUE;
        unset($issue['assigned_to']);
        yield 'no assignee' => [$issue];
    }

    /**
     * @param array<string, mixed> $issue
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('oldIssues')]
    public function testIssueWithoutNewFields(array $issue): void
    {
        $card = $this->twig->render('_partials/issue_card.html.twig', ['card' => Issue::fromDocument($issue)]);
        $listed = $this->twig->render('_partials/row/issue.html.twig', ['issue' => Issue::fromDocument($issue), 'size' => 'l']);

        foreach ([$card, $listed] as $html) {
            self::assertStringContainsString('Benni Mack', $html);
            self::assertSame(isset($issue['assigned_to']), str_contains($html, 'Benjamin Kott'));
        }
    }

    public function testIssueShowsTheAvatarsOfTheUserIndex(): void
    {
        $this->avatars(['forge:2' => md5('benjamin@example.org')]);

        $card = $this->twig->render('_partials/issue_card.html.twig', ['card' => Issue::fromDocument(self::OLD_ISSUE)]);
        $listed = $this->twig->render('_partials/row/issue.html.twig', ['issue' => Issue::fromDocument(self::OLD_ISSUE), 'size' => 'l']);

        // The assignee has one, the author none: the placeholder
        self::assertSame(2, substr_count($card, 'class="avatar"'));
        self::assertStringContainsString('https://www.gravatar.com/avatar/' . md5('') . '?s=36&amp;d=mp', $card);
        self::assertStringContainsString('https://www.gravatar.com/avatar/' . md5('benjamin@example.org') . '?s=36&amp;d=mp&amp;r=g', $card);
        self::assertStringContainsString('https://www.gravatar.com/avatar/' . md5('') . '?s=40&amp;d=mp', $listed);
    }

    public function testAnEmailAnOldIssueKeptShowsNoAvatarOfItsOwn(): void
    {
        $issue = self::OLD_ISSUE;
        $issue['assigned_to']['email'] = 'benjamin@example.org';

        $html = $this->twig->render('_partials/issue_card.html.twig', ['card' => Issue::fromDocument($issue)]);

        // Only the index "user" gives the avatars, the email stays in the document
        self::assertStringNotContainsString(md5('benjamin@example.org'), $html);
    }

    /**
     * @param array<string, string> $avatars hashes by "forge:<number>" and "gerrit:<number>"
     */
    private function avatars(array $avatars): void
    {
        $cache = self::getContainer()->get('cache.app');
        $cache->delete(self::AVATARS_CACHE_KEY);
        $cache->get(self::AVATARS_CACHE_KEY, static fn (): array => $avatars);
    }
}
