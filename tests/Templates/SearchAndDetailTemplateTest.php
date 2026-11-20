<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Elastic\StaleIssues;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewSortEnum;
use App\Enum\ReviewStateEnum;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The review lists with their search and sort field, the tabs of the search and the detail pages, rendered with
 * strict variables and documents of an older import, which lack the newer fields.
 */
final class SearchAndDetailTemplateTest extends KernelTestCase
{
    private const array CHANGE = [
        'id' => 81234,
        'reviewstatus' => 'NEW',
        'subject' => '[BUGFIX] Clear the cache',
        'reviewtags' => ['BUGFIX'],
        'branch' => 'main',
        'topic' => 'caching',
        'releases' => ['main'],
        'mergeable' => 'yes',
        'affected_files' => 1,
        'patchsets' => 3,
        'positive_reviews' => 1,
        'negative_reviews' => 0,
        'positive_verifications' => 1,
        'negative_verifications' => 0,
        'created_on' => '2026/11/01 10:00:00',
        'updated_on' => '2026/11/02 10:00:00',
        'owner' => 'Benni Mack',
    ];

    private const array ISSUE = [
        'id' => 91089,
        'subject' => 'Something is broken',
        'description' => 'Steps to reproduce',
        'tracker' => ['id' => 1, 'name' => 'Bug'],
        'status' => ['id' => 1, 'name' => 'New'],
        'author' => ['id' => 1, 'name' => 'Benni Mack'],
        'custom_fields' => [],
        'created_on' => '2026/11/01 10:00:00',
        'updated_on' => '2026/11/01 10:00:00',
    ];

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
        self::assertTrue($this->twig->isStrictVariables());
    }

    /**
     * @return array<string, mixed>
     */
    private function listData(string $query, array $sortParameter = []): array
    {
        return [
            'routeName' => 'gerrit_index',
            'results' => [],
            'aggregations' => ['Owner' => ['buckets' => [['key' => 'Benni Mack', 'doc_count' => 1]]]],
            'query' => $query,
            'filters' => ['owner' => ['Benni Mack' => 'true']],
            'sort' => ReviewSortEnum::fromRequest($sortParameter['sort'] ?? '', '' !== $query),
            'sortOptions' => ReviewSortEnum::options('' !== $query),
            'sortParameter' => $sortParameter,
            'listParameters' => '' === $query ? [] : ['query' => $query],
            'listSearch' => true,
            'pagesToLinkTo' => [],
            'currentPage' => 1,
            'totalResults' => 0,
            'startingAtItem' => 1,
            'endingAtItem' => 0,
        ];
    }

    public function testTheListSearchKeepsTheFiltersAndTheOrder(): void
    {
        $html = $this->twig->render('_partials/review_view.html.twig', $this->listData('cache', ['sort' => 'smallest']));

        self::assertStringContainsString('<input type="search" id="list-search-gerrit_index" name="query" value="cache"', $html);
        self::assertStringContainsString('<input type="hidden" name="filters[owner][Benni Mack]" value="true">', $html);
        self::assertStringContainsString('<input type="hidden" name="sort" value="smallest">', $html);
        self::assertStringContainsString('<option value="smallest" selected>Smallest first</option>', $html);
        self::assertStringContainsString('<option value="relevance">Relevance</option>', $html);
        self::assertStringContainsString('No change in this list matches &quot;cache&quot;.', $html);
        // The search in the "Owner" filter asks with the search words of the list
        self::assertStringContainsString('/gerrit/owners?list=gerrit_index&amp;query=cache', $html);
    }

    public function testWithoutSearchWordsThereIsNoRelevance(): void
    {
        $html = $this->twig->render('_partials/review_view.html.twig', $this->listData(''));

        self::assertStringNotContainsString('value="relevance"', $html);
        self::assertStringContainsString('<option value="updated" selected>Recently updated</option>', $html);
        self::assertStringContainsString('This list is empty.', $html);
    }

    public function testTheCountStandsAboveTheListAndThePagesBelowIt(): void
    {
        foreach ([true, false] as $listSearch) {
            $html = $this->twig->render('_partials/review_view.html.twig', [
                'results' => [['data' => self::CHANGE]], 'listSearch' => $listSearch,
                'pagesToLinkTo' => ['1' => 1, '2' => 2], 'totalResults' => 30, 'startingAtItem' => 1, 'endingAtItem' => 25,
                'listArguments' => [],
            ] + $this->listData(''));

            $count = strpos($html, 'class="pagination-count"');
            $list = strpos($html, 'class="row-list row-list-panel"');
            self::assertNotFalse($count);
            self::assertNotFalse($list);
            self::assertLessThan($list, $count);
            self::assertSame(1, substr_count($html, 'class="pagination"'));
            self::assertGreaterThan($list, strpos($html, 'class="pagination"'));
        }
    }

    public function testTheTabsKeepTheSearchWords(): void
    {
        $html = $this->twig->render('_partials/search_tabs.html.twig', [
            'active' => 'changes',
            'tabs' => ['issues' => 4, 'changes' => 2],
            'query' => 'cache',
            'searchClosed' => '1',
        ]);

        self::assertStringContainsString('href="/search?query=cache&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?query=cache&amp;searchClosed=1" aria-current="page"', $html);
        // The tabs of My Work: each with its icon and color
        self::assertMatchesRegularExpression('~class="view-tab is-issues" href="/search\\?query=cache&amp;searchClosed=1">.*?<span>Issues</span>\\s*<span class="view-tab-count" title="Issues found">4</span>~s', $html);
        self::assertStringContainsString('class="view-tab is-changes is-active"', $html);
    }

    public function testMyWorkHasNoOrangeBand(): void
    {
        $template = $this->twig->load('my/changes.html.twig');
        $html = $template->renderBlock('body', [
            'view' => 'changes', 'email' => 'me@example.org', 'name' => 'Me', 'includeClosed' => false, 'query' => '',
            'openChanges' => 2, 'openIssues' => 3, 'changes' => [], 'shown' => 0, 'total' => 0, 'selected' => null,
            'labels' => ['ready' => 'Ready for review', 'wip' => 'Work in progress'], 'counts' => [], 'closedStates' => [],
            'sort' => ReviewSortEnum::UPDATED, 'sortOptions' => ReviewSortEnum::options(false), 'isDefaultSort' => true,
        ]);

        // The layout shows the band only for a page with this block
        self::assertFalse($template->hasBlock('headline'));
        self::assertTrue($this->twig->load('review/generic_review_view.html.twig')->hasBlock('headline'));
        self::assertStringContainsString('<h1 class="sr-only">My Work</h1>', $html);
        self::assertStringContainsString('class="view-tab is-changes is-active"', $html);
        // Named as the tabs of the search
        self::assertStringContainsString('<span>Reviews</span>', $html);
        self::assertStringContainsString('<span>Issues</span>', $html);
        // Who the work is found for only where nothing is found
        self::assertStringNotContainsString('mywork-intro', $html);
        self::assertStringContainsString('Forger looks for the Gerrit changes owned by <strong>me@example.org</strong> or <strong>Me</strong>.', $html);
    }

    public function testMyReviewsAreSortableAndNotGrouped(): void
    {
        $html = $this->twig->load('my/changes.html.twig')->renderBlock('view', [
            'includeClosed' => false, 'query' => '', 'changes' => [self::CHANGE, ['id' => 81235] + self::CHANGE], 'shown' => 2, 'total' => 2,
            'selected' => 'wip', 'labels' => ['ready' => 'Ready for review', 'wip' => 'Work in progress'], 'counts' => ['wip' => 2],
            'closedStates' => [], 'sort' => ReviewSortEnum::SMALLEST, 'sortOptions' => ReviewSortEnum::options(false), 'isDefaultSort' => false,
        ]);

        self::assertStringContainsString('id="my-changes-sort"', $html);
        self::assertStringContainsString('<option value="smallest" selected>Smallest first</option>', $html);
        self::assertStringNotContainsString('value="relevance"', $html);
        // The sort field keeps the selected state, the state chips keep the order
        self::assertStringContainsString('<input type="hidden" name="review" value="wip">', $html);
        self::assertStringContainsString('href="/my/changes?review=wip&amp;sort=smallest"', $html);
        self::assertSame(2, substr_count($html, 'href="/change/'));
        self::assertStringNotContainsString('mywork-group', $html);
    }

    public function testMyWorkSearchKeepsFilterAndOrderAndTheTabsKeepTheWords(): void
    {
        $data = [
            'view' => 'changes', 'email' => 'me@example.org', 'name' => 'Me', 'includeClosed' => true, 'query' => 'cache',
            'openChanges' => 1, 'openIssues' => 3, 'changes' => [], 'shown' => 0, 'total' => 0, 'selected' => 'wip',
            'labels' => ['ready' => 'Ready for review', 'wip' => 'Work in progress'], 'counts' => ['wip' => 1], 'closedStates' => [],
            'sort' => ReviewSortEnum::SMALLEST, 'sortOptions' => ReviewSortEnum::options(true), 'isDefaultSort' => false,
        ];
        $html = $this->twig->load('my/changes.html.twig')->renderBlock('body', $data);

        // The search field instead of a title, the tab names the list
        self::assertStringContainsString('<input type="search" id="mywork-search" name="query" value="cache"', $html);
        self::assertStringContainsString('<h2 id="changes-title" class="sr-only">Reviews</h2>', $html);
        self::assertStringNotContainsString('Gerrit changes <span', $html);
        // New words keep the filter and the order, not the old words
        self::assertMatchesRegularExpression('~<form class="list-search mywork-search"[^>]*>.*name="review" value="wip".*name="sort" value="smallest"~s', $html);
        // Whether closed work counts, too, as in the search: a checkbox in the field
        self::assertStringContainsString('<input class="form-check-input" type="checkbox" name="state" value="all" checked onchange="this.form.submit()">', $html);
        self::assertStringNotContainsString('mywork-toggle', $html);
        $searchForm = substr($html, (int) strpos($html, 'mywork-search'));
        $searchForm = substr($searchForm, 0, (int) strpos($searchForm, '</form>'));
        self::assertStringNotContainsString('<input type="hidden" name="query"', $searchForm);
        self::assertStringNotContainsString('<input type="hidden" name="state"', $searchForm);
        // The order keeps them
        self::assertStringContainsString('<input type="hidden" name="query" value="cache">', $html);
        // The tabs, the switch and the chips keep the words
        self::assertStringContainsString('href="/my/issues?query=cache"', $html);
        self::assertStringContainsString('href="/my/changes?query=cache"', $html);
        self::assertStringContainsString('href="/my/changes?query=cache&amp;state=all&amp;review=wip&amp;sort=smallest"', $html);
        self::assertStringContainsString('None of your reviews that are work in progress matches “cache”.', $html);
    }

    public function testMyWorkSaysToWhomAnIssueIsAssigned(): void
    {
        $render = fn (array $issue): string => $this->twig->render('my/_issue_rows.html.twig', ['issues' => [$issue], 'name' => 'Benni Mack']);

        self::assertStringContainsString('>Unassigned</span>', $render(self::ISSUE));
        self::assertStringContainsString('>Assigned to you</span>', $render(['assigned_to' => ['name' => 'Benni Mack']] + self::ISSUE));
        self::assertStringContainsString('>for Oli Bartsch</span>', $render(['assigned_to' => ['name' => 'Oli Bartsch']] + self::ISSUE));
        // The rows of the search
        self::assertStringContainsString('overview-list-item kind-issue overview-list-item-detailed', $render(self::ISSUE));
    }

    public function testReviewsAndIssuesAreToldApart(): void
    {
        $issue = $this->twig->render('_partials/row/issue.html.twig', ['issue' => ['id' => 91089] + self::ISSUE]);
        $change = $this->twig->render('_partials/row/change.html.twig', ['change' => self::CHANGE]);

        self::assertStringContainsString('kind-issue', $issue);
        self::assertMatchesRegularExpression('~<span class="overview-list-item-id" title="Issue">.*#actions-list.*</span>#91089</span>~s', $issue);
        self::assertStringContainsString('kind-change', $change);
        self::assertMatchesRegularExpression('~<span class="overview-list-item-id" title="Review">.*#actions-code-pull-request.*</span>81234</span>~s', $change);
        self::assertStringNotContainsString('#81234', $change);
    }

    public function testStaleIssuesNameTheirChangesAPageAtATime(): void
    {
        $template = $this->twig->load('review/stale_issues.html.twig');
        $page = ['stages' => StaleIssues::STAGES, 'totalResults' => 30, 'startingAtItem' => 26, 'endingAtItem' => 28, 'pagesToLinkTo' => [0 => 1, 1 => 2], 'currentPage' => 2,
            'sort' => IssueSortEnum::PRIORITY, 'sortOptions' => IssueSortEnum::options(false), 'sortParameter' => ['sort' => 'priority']];
        $html = $template->renderBlock('body', $page + ['issues' => [
            ['issue' => ['id' => 91089, 'status' => ['name' => 'Under Review']] + self::ISSUE, 'changes' => []],
            ['issue' => ['id' => 91090] + self::ISSUE, 'changes' => [['id' => 81234, 'state' => ReviewStateEnum::MERGED], ['id' => 81240, 'state' => ReviewStateEnum::ABANDONED], ['id' => 67256, 'state' => null]]],
            ['issue' => ['id' => 91091, 'status' => ['name' => 'New']] + self::ISSUE, 'changes' => []],
        ]]);

        self::assertFalse($template->hasBlock('headline'));
        self::assertStringContainsString('<h1 class="page-intro-title">Stale issues</h1>', $html);
        // The stages of ActivityEnum, in its words
        self::assertStringContainsString('<strong>30</strong> open issues without activity for 60 days or more: stale (60 to 180 days) or orphaned (more than 180 days).', $html);
        // The sort field of the issue lists, without relevance
        self::assertStringContainsString('<form class="list-sort" action="/gerrit/stale-issues" method="get">', $html);
        self::assertStringContainsString('<option value="priority" selected>Priority</option>', $html);
        self::assertStringNotContainsString('value="relevance"', $html);
        self::assertStringContainsString('href="/issue/91089"', $html);
        self::assertStringContainsString('<span class="overview-list-item-note">Under review, but no change linked</span>', $html);
        self::assertStringContainsString('<span class="overview-list-item-note">Changes: 81234 merged · 81240 abandoned · 67256 not in Forger</span>', $html);
        // An issue not under review without a change needs no note
        self::assertSame(2, substr_count($html, 'overview-list-item-note'));
        // The count above, the pages below
        self::assertStringContainsString('Showing <strong>26</strong>–<strong>28</strong> of <strong>30</strong>', $html);
        self::assertStringContainsString('<li class="page-item active"><a class="page-link" href="/gerrit/stale-issues?sort=priority&amp;page=2">2</a></li>', $html);
        self::assertLessThan(strpos($html, 'class="pagination"'), strpos($html, 'row-list'));

        $html = $template->renderBlock('body', ['stages' => StaleIssues::STAGES, 'totalResults' => 0, 'issues' => []]);
        self::assertStringContainsString('Every open issue had activity within the last 60 days.', $html);
        self::assertStringNotContainsString('row-list', $html);
    }

    public function testTheFilterCreatedIsARangeAtTheEnd(): void
    {
        $render = fn (array $created): string => $this->twig->render('_partials/aggregations.html.twig', [
            'facetScope' => 'issues', 'dateFilters' => ['created' => ['title' => 'Created', 'value' => $created]],
            'aggregations' => ['Tracker' => ['buckets' => [['key' => 'Bug', 'doc_count' => 2]]]],
        ]);

        $html = $render(['range' => '2025-03-01 to 2025-03-31']);
        // After the other filters, two fields of months
        self::assertGreaterThan(strpos($html, 'facet-issues-tracker'), strpos($html, 'facet-issues-created'));
        // One field with the datepicker of the template bundle: the first and the last day in one calendar
        self::assertStringContainsString('name="filters[created][range]" value="2025-03-01 to 2025-03-31" placeholder="Pick the first and the last day" data-datepicker="{&quot;mode&quot;:&quot;range&quot;', $html);
        // Links with from and to show in it, too
        self::assertStringContainsString('value="2025-03 to 2025-06"', $render(['from' => '2025-03', 'to' => '2025-06']));
        // Open, and to be cleared, while a month is set
        self::assertStringContainsString('id="facet-issues-created" class="collapse show"', $html);
        self::assertStringContainsString('>Clear</button>', $html);

        $html = $render([]);
        self::assertStringContainsString('id="facet-issues-created" class="collapse"', $html);
        self::assertStringNotContainsString('>Clear</button>', $html);
    }

    public function testEveryFilterOfDatesHasTheDatepicker(): void
    {
        $html = $this->twig->render('_partials/aggregations.html.twig', [
            'facetScope' => 'changes',
            'dateFilters' => ['updated' => ['title' => 'Updated', 'value' => ['range' => '2026-11-01 to 2026-11-20']]],
            'aggregations' => ['Size' => ['buckets' => [['key' => 'S', 'doc_count' => 2]]]],
        ]);

        self::assertStringContainsString('<span class="aggregation-title-text">Updated</span>', $html);
        self::assertStringContainsString('<label class="sr-only" for="facet-changes-updated-days">Updated from and to</label>', $html);
        self::assertStringContainsString('name="filters[updated][range]" value="2026-11-01 to 2026-11-20" placeholder="Pick the first and the last day" data-datepicker=', $html);
        // Clear empties its own field only
        self::assertStringContainsString('[name^=&quot;filters[updated]&quot;]', $html);
        self::assertGreaterThan(strpos($html, 'facet-changes-size'), strpos($html, 'facet-changes-updated'));
    }

    public function testExactMatchesLeadToTheDetailPages(): void
    {
        $html = $this->twig->render('_partials/exact_matches.html.twig', ['exactMatches' => ['issue' => ['id' => 81234] + self::ISSUE, 'change' => self::CHANGE]]);

        self::assertStringContainsString('href="/issue/81234"', $html);
        self::assertStringContainsString('href="/change/81234"', $html);
        self::assertSame('', trim($this->twig->render('_partials/exact_matches.html.twig', ['exactMatches' => ['issue' => null, 'change' => null]])));
    }

    public function testChangePage(): void
    {
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => self::CHANGE, 'message' => "The cache is cleared.\n\nResolves: #91089\nRelated: #12", 'issues' => [['id' => 91089, 'relation' => 'Resolves', 'issue' => self::ISSUE], ['id' => 12, 'relation' => 'Related', 'issue' => null]]]);

        self::assertStringContainsString('[BUGFIX] Clear the cache', $html);
        self::assertStringContainsString('href="https://review.typo3.org/c/Packages/TYPO3.CMS/+/81234/"', $html);
        self::assertStringContainsString('href="/search/changes?query=caching"', $html);
        self::assertStringContainsString('>BUGFIX</a>', $html);
        // #12 is not imported: no link to a page Forger does not have
        // The text in the expander of the template bundle, the footer below it, outside
        self::assertMatchesRegularExpression('~<div class="expander-content-inner">\\s*<pre class="detail-message">The cache is cleared.</pre>~', $html);
        self::assertStringContainsString("<pre class=\"detail-message detail-message-footer\">Resolves: <a href=\"/issue/91089\">#91089</a>\nRelated: #12</pre>", $html);
        self::assertStringNotContainsString('Resolves:', explode('detail-message-footer', $html)[0]);
        self::assertStringContainsString('<span class="overview-list-relation">Resolved by this change</span>', $html);
        self::assertStringContainsString('<span class="overview-list-relation">Related to this change</span>', $html);
        self::assertStringContainsString('href="/issue/91089"', $html);
        // Not imported: Forge has it
        self::assertStringContainsString('href="https://forge.typo3.org/issues/12"', $html);
        // Only admins refresh
        self::assertStringNotContainsString('refresh-form', $html);
    }

    public function testTheFilesOfAChangeAreSortedAsATree(): void
    {
        $change = self::CHANGE + ['files' => ['composer.json', 'typo3/sysext/core/Classes/Cache.php', 'Build/Sources/cache.ts', 'typo3/sysext/backend/Classes/Page.php']];

        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => $change, 'message' => '', 'issues' => []]);

        preg_match_all('~<a href="/gerrit/files\?query=[^"]+" title="([^"]+): other changes touching this file">~', $html, $matches);
        self::assertSame(['Build/Sources/cache.ts', 'typo3/sysext/backend/Classes/Page.php', 'typo3/sysext/core/Classes/Cache.php', 'composer.json'], $matches[1]);
    }

    public function testTheFactsOfAChangeLeadToItsFilters(): void
    {
        $change = ['releases' => ['main', '14.3']] + self::CHANGE;
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => $change, 'message' => '', 'issues' => []]);

        // As the checkboxes of the filters set them, spaces as "_"
        self::assertStringContainsString('<a href="/search/changes?filters%5Bowner%5D%5BBenni_Mack%5D=true" title="Changes by them">Benni Mack</a>', $html);
        // Every release a link of its own
        self::assertStringContainsString('<dd><a href="/search/changes?filters%5Breleases%5D%5Bmain%5D=true" title="Changes for this release">main</a>, <a href="/search/changes?filters%5Breleases%5D%5B14.3%5D=true" title="Changes for this release">14.3</a></dd>', $html);
        self::assertStringContainsString('<a href="/search/changes?filters%5Bpatchsets%5D%5B3%5D=true" title="Changes with as many patch sets">3</a>', $html);
        self::assertStringNotContainsString('searchClosed', $html);
        // Without releases no fact
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => ['releases' => []] + self::CHANGE, 'message' => '', 'issues' => []]);
        self::assertStringNotContainsString('Target releases', $html);

        // A merged change is among the changes its links find: they search the closed ones, too
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => ['reviewstatus' => 'MERGED'] + $change, 'message' => '', 'issues' => []]);
        self::assertStringContainsString('href="/search/changes?filters%5Bowner%5D%5BBenni_Mack%5D=true&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?filters%5Breleases%5D%5B14.3%5D=true&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?filters%5Bpatchsets%5D%5B3%5D=true&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?filters%5Btype%5D%5BBUGFIX%5D=true&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?query=caching&amp;searchClosed=1"', $html);
    }

    public function testTheTagsOfAChangeLeadToTheSearch(): void
    {
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => self::CHANGE, 'message' => '', 'issues' => []]);

        self::assertStringContainsString('<a class="tag-label tag-label-bugfix" href="/search/changes?filters%5Btype%5D%5BBUGFIX%5D=true" title="Bugfix: the changes with this tag">BUGFIX</a>', $html);
        // Not in the rows
        self::assertStringNotContainsString('<a class="tag-label', $this->twig->render('_partials/row/change.html.twig', ['change' => self::CHANGE, 'detailed' => true]));
    }

    public function testTheFilesAfterTheFirstTwentyAreCollapsed(): void
    {
        $files = array_map(static fn (int $number): string => sprintf('Classes/File%02d.php', $number), range(1, 23));
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => self::CHANGE + ['files' => $files], 'message' => '', 'issues' => []]);

        [$shown, $collapsed] = explode('<details class="detail-files-more">', $html);
        self::assertSame(20, substr_count($shown, ': other changes touching this file"'));
        self::assertStringContainsString('<summary>Show 3 more files</summary>', $collapsed);
        self::assertSame(3, substr_count($collapsed, ': other changes touching this file"'));
        // The order of the tree goes on in the collapsed ones
        self::assertStringContainsString('Classes/File20.php', $shown);
        self::assertStringContainsString('Classes/File21.php', $collapsed);

        // In one line: the folder may give way, the name not
        self::assertStringContainsString('<span class="detail-file-folder">Classes/</span><span class="detail-file-name">File01.php</span>', $shown);

        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => self::CHANGE + ['files' => array_slice($files, 0, 20)], 'message' => '', 'issues' => []]);
        self::assertStringNotContainsString('detail-files-more', $html);
    }

    public function testTheSearchFormKeepsWordsAndClosedOnes(): void
    {
        $html = $this->twig->render('_partials/search_form.html.twig', ['route' => 'search_changes', 'query' => 'cache "warm"', 'searchClosed' => '1']);

        self::assertStringContainsString('action="/search/changes"', $html);
        self::assertStringContainsString('value="cache &quot;warm&quot;"', $html);
        self::assertMatchesRegularExpression('~name="searchClosed" value="1" checked~', $html);
        self::assertMatchesRegularExpression('~>\\s*Include closed\\s*</label>~', $html);

        $html = $this->twig->render('_partials/search_form.html.twig', ['route' => 'search_search']);
        self::assertStringNotContainsString(' checked', $html);
        // The same words on both tabs: the head does not move between them
        self::assertMatchesRegularExpression('~>\\s*Include closed\\s*</label>~', $html);
    }

    public function testTheFactsOfAnIssueLeadToTheSearch(): void
    {
        $issue = self::ISSUE + [
            'category' => ['id' => 1, 'name' => 'Link Handling'],
            'typo3_version' => 14,
            'tags' => ['preview'],
            'complexity' => 'easy',
        ];
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => $issue, 'changes' => [], 'unknownChanges' => []]);

        // As the checkboxes of the search write the filters, a space as "_"
        self::assertStringContainsString('<a href="/search?filters%5Bcategory%5D%5BLink_Handling%5D=true" title="Issues in this category">Link Handling</a>', $html);
        self::assertStringContainsString('<a href="/search?filters%5Bauthor%5D%5BBenni_Mack%5D=true" title="Issues by them">Benni Mack</a>', $html);
        self::assertStringContainsString('href="/search?filters%5Btypo3_version%5D%5B14%5D=true"', $html);
        self::assertStringContainsString('<a class="tag-label" href="/search?filters%5Btags%5D%5Bpreview%5D=true" title="Issues with this tag">preview</a>', $html);
        // The search has no filter for it
        self::assertStringContainsString('<dd>easy</dd>', $html);
        // "-" is the value of the import for none: no fact, no "v-" in a row
        $none = ['typo3_version' => '-', 'php_version' => '-', 'complexity' => '-'] + self::ISSUE;
        $page = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => $none, 'changes' => [], 'unknownChanges' => []]);
        self::assertStringNotContainsString('<dt>TYPO3 version</dt>', $page);
        self::assertStringNotContainsString('<dt>Complexity</dt>', $page);
        self::assertStringNotContainsString('title="TYPO3 version">v-', $this->twig->render('_partials/row/issue.html.twig', ['issue' => $none, 'detailed' => true]));

        // Forger has only one project
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => $issue + ['project' => ['id' => 27, 'name' => 'TYPO3 Core']], 'changes' => [], 'unknownChanges' => []]);
        self::assertStringNotContainsString('<dt>Project</dt>', $html);
    }

    public function testIssuePage(): void
    {
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', [
            'issue' => self::ISSUE + ['journals' => [['user' => ['name' => 'Gerrit Code Review'], 'notes' => 'Pushed', 'created_on' => '2026-11-02T10:00:00Z']]],
            'changes' => [],
            'unknownChanges' => [81234],
        ]);

        self::assertStringContainsString('href="/issue/91089/duplicates"', $html);
        self::assertStringContainsString('href="https://forge.typo3.org/issues/91089"', $html);
        self::assertStringContainsString('Change 81234</a>', $html);
        self::assertStringContainsString('Gerrit Code Review', $html);
    }

    public function testIssuePageWithoutChanges(): void
    {
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => self::ISSUE, 'changes' => [], 'unknownChanges' => []]);

        self::assertStringContainsString('No change resolves or names this issue', $html);
    }

    public function testIssuePageTellsHowAChangeBelongsToIt(): void
    {
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => self::ISSUE, 'changes' => [['change' => self::CHANGE, 'relation' => 'Resolves']], 'unknownChanges' => []]);

        self::assertStringContainsString('<span class="overview-list-relation">Resolves this issue</span>', $html);
        self::assertStringContainsString('href="/change/81234"', $html);
    }

    public function testListsLeadToTheDetailPages(): void
    {
        self::assertStringContainsString('href="/change/81234"', $this->twig->render('_partials/row/change.html.twig', ['change' => self::CHANGE, 'detailed' => true]));
        self::assertStringContainsString('href="/issue/91089"', $this->twig->render('_partials/row/issue.html.twig', ['issue' => self::ISSUE, 'detailed' => true]));
    }

    public function testTheBreadcrumbLinksAllButThePageItself(): void
    {
        $html = $this->twig->createTemplate(
            "{% import '_macros/breadcrumb.html.twig' as breadcrumb %}{{ breadcrumb.trail([{'label': 'Issues', 'url': '/search'}, {'label': '#91089', 'url': '/issue/91089'}, {'label': 'Possible duplicates'}]) }}"
        )->render();

        self::assertStringContainsString('<nav class="breadcrumb-nav" aria-label="Breadcrumb">', $html);
        self::assertStringContainsString('<li class="breadcrumb-item"><a href="/issue/91089">#91089</a></li>', $html);
        self::assertStringContainsString('<li class="breadcrumb-item active" aria-current="page">Possible duplicates</li>', $html);
    }

    public function testDetailPagesHaveABreadcrumb(): void
    {
        $change = $this->twig->load('change/show.html.twig')->renderBlock('breadcrumb', ['change' => self::CHANGE]);
        $issue = $this->twig->load('issue/duplicates.html.twig')->renderBlock('breadcrumb', ['issue' => self::ISSUE]);

        self::assertStringContainsString('<a href="/search/changes">Reviews</a>', $change);
        self::assertStringContainsString('aria-current="page">Change 81234</li>', $change);
        self::assertStringContainsString('<a href="/issue/91089">#91089</a>', $issue);
    }

    public function testTheHelpListsTheSizesOfTheEnum(): void
    {
        $html = $this->twig->load('static_page/help.html.twig')->renderBlock('body', []);

        self::assertStringContainsString('status-pill-size-easy" title="up to 50 lines changed">Easy</span>', $html);
        self::assertMatchesRegularExpression('~251–1000 lines~', $html);
        self::assertMatchesRegularExpression('~2501–10000 lines~', $html);
        self::assertStringContainsString('status-pill-size-impossible" title="more than 10000 lines changed">Impossible</span>', $html);
        self::assertMatchesRegularExpression('~over 10000 lines~', $html);
    }

    public function testAMergeConflictShowsInEveryRow(): void
    {
        $html = $this->twig->render('_partials/row/change.html.twig', ['change' => ['mergeable' => 'no'] + self::CHANGE]);

        // The pill alone, without a bar beside the row
        self::assertStringNotContainsString('has-conflict', $html);
        self::assertStringContainsString('>Merge conflict</span>', $html);
        self::assertStringNotContainsString('Merge conflict', $this->twig->render('_partials/row/change.html.twig', ['change' => self::CHANGE]));
    }

    public function testTheChangePageNamesAMergeConflict(): void
    {
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => ['mergeable' => 'no'] + self::CHANGE, 'message' => '', 'issues' => []]);

        self::assertStringContainsString('<p class="detail-notice detail-notice-danger" role="status">', $html);
        self::assertStringContainsString('It needs a rebase onto main.', $html);
    }

    public function testListsShowTheSize(): void
    {
        $html = $this->twig->render('_partials/row/change.html.twig', ['change' => self::CHANGE + ['insertions' => 40, 'deletions' => 20], 'detailed' => true]);

        self::assertStringContainsString('status-pill-size-normal" title="Normal: +40 −20 lines, up to 250 lines changed">Normal</span>', $html);
    }
}
