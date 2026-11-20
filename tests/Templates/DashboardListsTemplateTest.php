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
 * The short lists of the dashboard, rendered with strict variables.
 */
final class DashboardListsTemplateTest extends KernelTestCase
{
    /**
     * An issue without the optional fields: no category, nobody assigned.
     */
    private const array ISSUE = [
        'id' => 91089,
        'subject' => 'Something is broken',
        'tracker' => ['id' => 1, 'name' => 'Bug'],
        'status' => ['id' => 1, 'name' => 'New'],
        'author' => ['id' => 1, 'name' => 'Benni Mack'],
        'created_on' => '2026/11/01 10:00:00',
        'updated_on' => '2026/11/01 10:00:00',
    ];

    /**
     * A backport ready to merge, without the email of its owner.
     */
    private const array CHANGE = [
        'id' => 12345,
        'subject' => '[BUGFIX] Repair the cache',
        'branch' => '13.4',
        'owner' => 'Benni Mack',
        'updated_on' => '2026/11/01 10:00:00',
    ];

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
        self::assertTrue($this->twig->isStrictVariables());
    }

    public function testNewIssues(): void
    {
        $html = $this->twig->render('dashboard/_new_issues.html.twig', ['issues' => ['documents' => [Issue::fromDocument(self::ISSUE)], 'total' => 1]]);

        self::assertStringContainsString('href="/issue/91089"', $html);
        self::assertStringContainsString('Something is broken', $html);
        self::assertStringContainsString('<span class="user-name">Benni Mack</span>', $html);
        self::assertStringContainsString('status-pill-new', $html);
        self::assertStringContainsString('tag-label-bug', $html);
        // Without category and version their details are left out
        self::assertStringNotContainsString('title="Category"', $html);
        self::assertStringContainsString('/search?sort=newest', $html);
        self::assertStringNotContainsString('There are no open issues.', $html);
    }

    public function testNewIssueWithAllDetails(): void
    {
        $issue = self::ISSUE + [
            'priority' => ['id' => 3, 'name' => 'Must have'],
            'category' => ['id' => 1, 'name' => 'Backend'],
            'typo3_version' => '13',
        ];
        $html = $this->twig->render('dashboard/_new_issues.html.twig', ['issues' => ['documents' => [Issue::fromDocument($issue)], 'total' => 1]]);

        // The priority is not of interest in a list, the page of the issue has it
        self::assertStringNotContainsString('Must have', $html);
        self::assertStringContainsString('<span title="Category">Backend</span>', $html);
        self::assertStringContainsString('v13', $html);
    }

    public function testChangeReadyToMergeWithAllDetails(): void
    {
        $change = self::CHANGE + [
            'positive_reviews' => 3,
            'positive_verifications' => 2,
            // A +2 and a +1 review, two +1 verifications
            'lowest_review_vote' => 0,
            'highest_review_vote' => 2,
            'lowest_verification_vote' => 0,
            'highest_verification_vote' => 1,
            'releases' => ['main', '13.4', 'omitted'],
            'topic' => 'cache',
            'insertions' => 12,
            'deletions' => 3,
        ];
        $html = $this->twig->render('dashboard/_ready_to_merge.html.twig', ['changes' => ['documents' => [Change::fromDocument($change)], 'total' => 1]]);

        self::assertStringContainsString('status-pill-vote-approved" title="Code review: +2 by a merger · 3 for, 0 against">CR +2</span>', $html);
        self::assertStringContainsString('status-pill-vote-recommended" title="Verified: +1 · 2 for, 0 against">V +1</span>', $html);
        // The branch of the change and "omitted" are no target releases
        self::assertStringContainsString('→ main</span>', $html);
        self::assertStringContainsString('<span title="Topic">cache</span>', $html);
        self::assertStringContainsString('<span class="is-added">+12</span> <span class="is-removed">&minus;3</span>', $html);
    }

    public function testNoNewIssues(): void
    {
        self::assertStringContainsString('There are no open issues.', $this->twig->render('dashboard/_new_issues.html.twig', ['issues' => ['documents' => [], 'total' => 0]]));
    }

    public function testReadyToMerge(): void
    {
        $html = $this->twig->render('dashboard/_ready_to_merge.html.twig', ['changes' => ['documents' => [Change::fromDocument(self::CHANGE)], 'total' => 7]]);

        self::assertStringContainsString('href="/change/12345"', $html);
        self::assertStringContainsString('[BUGFIX] Repair the cache', $html);
        self::assertStringContainsString('<span class="user-name">Benni Mack</span>', $html);
        // Votes missing in old documents count as none
        self::assertStringContainsString('status-pill-vote-none" title="Code review: no votes · 0 for, 0 against">CR 0</span>', $html);
        self::assertStringNotContainsString('Lines added and removed', $html);
        self::assertStringContainsString('tag-label is-backport', $html);
        self::assertStringContainsString('<span class="overview-list-count">7</span>', $html);
        self::assertStringContainsString('/gerrit/core-team-merge', $html);
    }

    public function testNothingReadyToMerge(): void
    {
        self::assertStringContainsString('No changes are ready to merge.', $this->twig->render('dashboard/_ready_to_merge.html.twig', ['changes' => ['documents' => [], 'total' => 0]]));
    }
}
