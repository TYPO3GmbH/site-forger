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
use App\Elastic\SearchResult;
use App\People\PersonalFigures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The dashboard and its page of statistics, rendered with strict variables.
 */
final class DashboardTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
        self::assertTrue($this->twig->isStrictVariables());
    }

    public function testTheDashboardListsWhatNeedsAttentionAndLeadsToTheStatistics(): void
    {
        $change = Change::fromDocument([
            'id' => 81234,
            'subject' => '[TASK] Tidy up',
            'reviewstatus' => 'NEW',
            'updated_on' => '2026/01/01 10:00:00',
        ]);
        $issue = Issue::fromDocument([
            'id' => 91089,
            'subject' => 'Something is broken',
            'status' => ['id' => 1, 'name' => 'New'],
            'isregression' => true,
            'created_on' => '2026/11/01 10:00:00',
        ]);
        $merged = Change::fromDocument([
            'id' => 81235,
            'subject' => '[BUGFIX] Clear the cache',
            'reviewstatus' => 'MERGED',
            'branch' => '13.4',
            'owner' => 'Benni Mack',
            'ownerEmail' => 'benni@example.org',
            'resolves' => [91089, 91090],
            'updated_on' => '2026/11/20 10:00:00',
        ]);
        $html = $this->twig->load('dashboard/index.html.twig')->renderBlock('body', [
            'openIssues' => 1553,
            'openedIssues' => 324,
            'resolvedIssues' => 453,
            'statisticsRange' => '30d',
            'newIssues' => SearchResult::ofDocuments([$issue], 1),
            'readyToMerge' => SearchResult::ofDocuments([], 0),
            'regressions' => SearchResult::ofDocuments([$issue], 35),
            'recentlyMerged' => SearchResult::ofDocuments([$merged], 1),
            'changes' => [],
            'changeLabels' => [],
            'changesTotal' => 0,
            'waitingChanges' => SearchResult::ofDocuments([$change], 80),
            'waitingFilter' => ['updated' => ['range' => '2000-01-01 to 2026-10-20']],
            'personal' => null,
        ]);

        self::assertStringContainsString('What needs attention', $html);
        // The note of 30 days counts like the statistics it links to
        self::assertStringContainsString('href="/statistics?range=30d"', $html);
        // The figure and the list count the same changes
        self::assertStringContainsString('Waiting over a month <span class="overview-list-count">80</span>', $html);
        self::assertMatchesRegularExpression('~overview-tone-warning">80</span>~', $html);
        self::assertStringContainsString(
            'href="/reviews/ready?filters%5Bupdated%5D%5Brange%5D=2000-01-01%20to%202026-10-20&amp;sort=least-updated"',
            $html,
        );
        // No figure of regressions beside their list
        self::assertSame(1, substr_count($html, 'Open regressions'));
        self::assertStringContainsString('Open regressions <span class="overview-list-count">35</span>', $html);
        self::assertStringContainsString('href="/get-involved"', $html);
        self::assertStringNotContainsString('js-chart', $html);
        // A guest has no work of the own
        self::assertStringNotContainsString('For you', $html);
    }

    public function testTheWorkOfTheUserLoggedInComesWithRowsToActOn(): void
    {
        $change = Change::fromDocument([
            'id' => 81234,
            'subject' => '[TASK] Something',
            'reviewstatus' => 'NEW',
            'updated_on' => '2026/11/20 10:00:00',
        ]);
        $issue = Issue::fromDocument([
            'id' => 91089,
            'subject' => 'Something is broken',
            'status' => ['id' => 1, 'name' => 'New'],
            'created_on' => '2026/11/01 10:00:00',
        ]);
        $html = $this->twig->render('dashboard/_for_you.html.twig', [
            'personal' => new PersonalFigures(
                2,
                1,
                1,
                148,
                4,
                3,
                27,
                20,
                0,
                [['kind' => 'change', 'item' => $change], ['kind' => 'issue', 'item' => $issue]],
                [['kind' => 'change', 'item' => $change]],
            ),
        ]);

        self::assertStringContainsString('For you', $html);
        self::assertStringContainsString('Waiting for you <span class="overview-list-count">3</span>', $html);
        self::assertStringContainsString('Your interests <span class="overview-list-count">152</span>', $html);
        self::assertStringContainsString('People you follow <span class="overview-list-count">47</span>', $html);
        // What a number counts, with the noun in the singular for one
        self::assertStringContainsString('2 changes need a step · 1 issue assigned', $html);
        self::assertStringContainsString('148 changes to review · 4 new issues', $html);
        self::assertStringContainsString('27 stuck · 20 without a review · 0 without an answer', $html);
        // The rows lead to the change and the issue, the card to all of it
        self::assertSame(2, substr_count($html, 'href="/change/81234"'));
        self::assertSame(1, substr_count($html, 'href="/issue/91089"'));
        self::assertStringContainsString('href="/my/following">Show all</a>', $html);
        self::assertStringContainsString('Nobody you follow needs help right now.', $html);
    }

    public function testWithoutInterestsAndPeopleTheCardsLeadToAddingThem(): void
    {
        $html = $this->twig->render('dashboard/_for_you.html.twig', [
            'personal' => new PersonalFigures(0, 0, 0, 0, 0, 0, 0, 0, 0),
        ]);

        self::assertStringContainsString('Nothing waits for you.', $html);
        self::assertStringContainsString('href="/my/interests/new"', $html);
        self::assertStringContainsString('Add an interest', $html);
        self::assertStringContainsString('href="/my/following/manage"', $html);
        self::assertStringContainsString('Follow people', $html);
        self::assertSame(1, substr_count($html, 'Show all'));
    }

    public function testTheMergesThankTheirAuthors(): void
    {
        $merged = Change::fromDocument([
            'id' => 81235,
            'subject' => '[BUGFIX] Clear the cache',
            'reviewstatus' => 'MERGED',
            'branch' => '13.4',
            'owner' => 'Benni Mack',
            'ownerEmail' => 'benni@example.org',
            'resolves' => [91089, 91090],
            'updated_on' => '2026/11/20 10:00:00',
            'reviewtags' => ['BUGFIX'],
            'votes' => [
                ['label' => 'Code-Review', 'value' => 2, 'account' => ['id' => 2, 'name' => 'Georg Ringer']],
                ['label' => 'Verified', 'value' => 1, 'account' => ['id' => 49454, 'name' => 'core-ci']],
            ],
        ]);
        $html = $this->twig->render(
            'dashboard/_recently_merged.html.twig',
            ['changes' => SearchResult::ofDocuments([$merged], 1)],
        );

        self::assertStringContainsString('Recently merged', $html);
        self::assertStringContainsString('<span class="overview-merged-name">Benni Mack</span>', $html);
        self::assertStringContainsString('href="/change/81235"', $html);
        self::assertStringContainsString('#91089, #91090', $html);
        // The type as a tag, the title without it, the reviewers without the CI
        self::assertStringContainsString('<span class="tag-label tag-label-bugfix">BUGFIX</span>', $html);
        self::assertStringContainsString('<span class="overview-merged-subject">Clear the cache</span>', $html);
        self::assertStringContainsString('title="Reviewed by Georg Ringer"', $html);
        self::assertStringNotContainsString('core-ci', $html);
        self::assertStringContainsString('is-backport', $html);
        // The address is for the avatar only
        self::assertStringNotContainsString('benni@example.org', $html);
    }

    public function testTheStatisticsAreTheBoardOfTheActivity(): void
    {
        $html = $this->twig->load('dashboard/statistics.html.twig')->renderBlock('body');

        self::assertStringContainsString('view="statistics"', $html);
        self::assertStringContainsString('closed-years-api-url="/api/v1/activity/closed-years"', $html);
        self::assertStringNotContainsString('js-chart', $html);
    }
}
