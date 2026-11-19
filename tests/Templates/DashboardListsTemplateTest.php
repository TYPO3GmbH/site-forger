<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

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
        $html = $this->twig->render('dashboard/_new_issues.html.twig', ['issues' => ['documents' => [self::ISSUE], 'total' => 1]]);

        self::assertStringContainsString('https://forge.typo3.org/issues/91089', $html);
        self::assertStringContainsString('Something is broken', $html);
        self::assertStringContainsString('by Benni Mack', $html);
        self::assertStringContainsString('status-pill-new', $html);
        self::assertStringContainsString('mywork-tag-bug', $html);
        // Without category, version and priority their details are left out
        self::assertStringNotContainsString('title="Category"', $html);
        self::assertStringNotContainsString('title="Priority"', $html);
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
        $html = $this->twig->render('dashboard/_new_issues.html.twig', ['issues' => ['documents' => [$issue], 'total' => 1]]);

        self::assertStringContainsString('priority-must" title="Priority">Must have', $html);
        self::assertStringContainsString('<span title="Category">Backend</span>', $html);
        self::assertStringContainsString('v13', $html);
    }

    public function testChangeReadyToMergeWithAllDetails(): void
    {
        $change = self::CHANGE + [
            'positive_reviews' => 3,
            'positive_verifications' => 2,
            'releases' => ['main', '13.4', 'omitted'],
            'topic' => 'cache',
            'insertions' => 12,
            'deletions' => 3,
        ];
        $html = $this->twig->render('dashboard/_ready_to_merge.html.twig', ['changes' => ['documents' => [$change], 'total' => 1]]);

        self::assertStringContainsString('<span class="mywork-vote is-positive" title="Code review: 3 positive">CR <b>+3</b></span>', $html);
        self::assertStringContainsString('<span class="mywork-vote is-positive" title="Verified: 2 positive">V <b>+2</b></span>', $html);
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
        $html = $this->twig->render('dashboard/_ready_to_merge.html.twig', ['changes' => ['documents' => [self::CHANGE], 'total' => 7]]);

        self::assertStringContainsString('https://review.typo3.org/c/Packages/TYPO3.CMS/+/12345/', $html);
        self::assertStringContainsString('[BUGFIX] Repair the cache', $html);
        self::assertStringContainsString('by Benni Mack', $html);
        // Votes missing in old documents count as none
        self::assertStringContainsString('<span class="mywork-vote" title="Code review: 0 positive">CR <b>+0</b></span>', $html);
        self::assertStringNotContainsString('Lines added and removed', $html);
        self::assertStringContainsString('mywork-tag is-backport', $html);
        self::assertStringContainsString('<span class="overview-list-count">7</span>', $html);
        self::assertStringContainsString('/gerrit/core-team-merge', $html);
    }

    public function testNothingReadyToMerge(): void
    {
        self::assertStringContainsString('No changes are ready to merge.', $this->twig->render('dashboard/_ready_to_merge.html.twig', ['changes' => ['documents' => [], 'total' => 0]]));
    }
}
