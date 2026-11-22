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
use App\Document\DuplicatePair;
use App\Document\Issue;
use App\Document\RelatedChange;
use App\Document\RelatedIssue;
use App\Document\Relations;
use App\DTO\Pagination;
use App\Duplicates\DuplicateGroups;
use App\Duplicates\DuplicateHit;
use App\Elastic\Bucket;
use App\Elastic\SearchResult;
use App\Enum\ActivityEnum;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewSortEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\TrackerEnum;
use App\Report\Backport\BackportRow;
use App\Report\Backport\BackportVersion;
use App\Report\CategoryOverview;
use App\Report\DuplicatePairs;
use App\Report\LinkedChange;
use App\Report\MonthOverview;
use App\Report\StaleIssue;
use App\Report\StaleIssues;
use App\Search\IssueStatusCount;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
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
     * @param array<string, string> $sortParameter
     *
     * @return array<string, mixed>
     */
    private function listData(string $query, array $sortParameter = []): array
    {
        return [
            'routeName' => 'reviews_ready',
            'changes' => [],
            'aggregations' => ['Owner' => Bucket::facetOf(['buckets' => [['key' => 'Benni Mack', 'doc_count' => 1]]])],
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

        self::assertStringContainsString('<input type="search" id="list-search-reviews_ready" name="query" value="cache"', $html);
        self::assertStringContainsString('<input type="hidden" name="filters[owner][Benni Mack]" value="true">', $html);
        self::assertStringContainsString('<input type="hidden" name="sort" value="smallest">', $html);
        self::assertStringContainsString('<option value="smallest" selected>Smallest first</option>', $html);
        self::assertStringContainsString('<option value="relevance">Relevance</option>', $html);
        // The filters find nothing: they stay, with the way back to the list without them, its words and order kept
        self::assertStringContainsString('No change in this list matches &quot;cache&quot; with the filters.', $html);
        self::assertStringContainsString('Untick a filter, or <a href="/reviews/ready?query=cache&amp;sort=smallest">remove the filters.</a>', $html);
        // The search in the "Owner" filter asks with the search words of the list
        self::assertStringContainsString('/reviews/owners?list=reviews_ready&amp;query=cache', $html);
    }

    public function testWithoutSearchWordsThereIsNoRelevance(): void
    {
        $html = $this->twig->render('_partials/review_view.html.twig', $this->listData(''));

        self::assertStringNotContainsString('value="relevance"', $html);
        self::assertStringContainsString('<option value="updated" selected>Recently updated</option>', $html);
        self::assertStringContainsString('No change in this list matches the filters.', $html);
        self::assertStringContainsString('<a href="/reviews/ready">remove the filters.</a>', $html);
        $data = ['filters' => ['file' => ['path' => '']]] + $this->listData('');
        self::assertStringContainsString('This list is empty.', $this->twig->render('_partials/review_view.html.twig', $data));
    }

    public function testTheCountStandsAboveTheListAndThePagesBelowIt(): void
    {
        foreach ([true, false] as $listSearch) {
            $html = $this->twig->render('_partials/review_view.html.twig', [
                'changes' => [Change::fromDocument(self::CHANGE)], 'listSearch' => $listSearch,
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
        self::assertMatchesRegularExpression('~class="view-tab is-issues"[^>]* href="/search\\?query=cache&amp;searchClosed=1">.*?<span>Issues</span>\\s*<span class="view-tab-count" title="Issues found">4</span>~s', $html);
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

        // No title of its own: the tabs say where the page is
        self::assertFalse($template->hasBlock('page_title'));
        self::assertTrue($this->twig->load('review/generic_review_view.html.twig')->hasBlock('page_title'));
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
            'includeClosed' => false, 'query' => '', 'changes' => [Change::fromDocument(self::CHANGE), Change::fromDocument(['id' => 81235] + self::CHANGE)], 'shown' => 2, 'total' => 2,
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

    public function testMyIssuesHaveAChipPerStatusTheClosedOnesHidden(): void
    {
        $html = $this->twig->load('my/issues.html.twig')->renderBlock('view', [
            'includeClosed' => false,
            'query' => '',
            'issues' => [Issue::fromDocument(self::ISSUE)],
            'shown' => 1,
            'total' => 1,
            'selected' => null,
            'statusCounts' => IssueStatusCount::listOf([5 => 2, 1 => 1, 8 => 3]),
            'allCount' => 4,
            'hidden' => 2,
            'name' => 'Me',
            'email' => 'me@example.org',
            'sort' => IssueSortEnum::UPDATED,
            'sortOptions' => IssueSortEnum::options(false),
            'isDefaultSort' => true,
        ]);

        // In the order of the workflow, each linked by the id of its status
        self::assertMatchesRegularExpression('~New.*Under Review.*Closed~s', $html);
        self::assertStringContainsString('href="/my/issues?status=8"', $html);
        self::assertStringContainsString('2 resolved, closed or rejected issues are hidden.', $html);
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
        self::assertMatchesRegularExpression('~<form class="search-field search-field-small"[^>]*>.*name="review" value="wip".*name="sort" value="smallest"~s', $html);
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
        $render = fn (array $issue): string => $this->twig->render('my/_issue_rows.html.twig', ['issues' => [Issue::fromDocument($issue)], 'name' => 'Benni Mack']);

        self::assertStringContainsString('>Unassigned</span>', $render(self::ISSUE));
        self::assertStringContainsString('>Assigned to you</span>', $render(['assigned_to' => ['name' => 'Benni Mack']] + self::ISSUE));
        // Someone else: the person, as everywhere, with the role on hover
        $other = $render(['assigned_to' => ['name' => 'Oli Bartsch']] + self::ISSUE);
        self::assertStringContainsString('<span class="user" title="Assigned to: Oli Bartsch"><span class="user-avatar" aria-hidden="true"><img src="https://www.gravatar.com/avatar/' . md5('') . '?s=40&amp;d=mp&amp;r=g" class="avatar" height="40" width="40" alt=""/></span><span class="user-name">Oli Bartsch</span></span>', $other);
        self::assertStringNotContainsString('for Oli Bartsch', $other);
        // The rows of the search
        self::assertStringContainsString('overview-list-item kind-issue overview-list-item-detailed', $render(self::ISSUE));
    }

    public function testReviewsAndIssuesAreToldApart(): void
    {
        $issue = $this->twig->render('_partials/row/issue.html.twig', ['issue' => Issue::fromDocument(['id' => 91089] + self::ISSUE)]);
        $change = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument(self::CHANGE)]);

        self::assertStringContainsString('kind-issue', $issue);
        self::assertMatchesRegularExpression('~<span class="overview-list-item-id" title="Issue">.*#actions-list.*</span>#91089</span>~s', $issue);
        self::assertStringContainsString('kind-change', $change);
        self::assertMatchesRegularExpression('~<span class="overview-list-item-id" title="Review">.*#actions-code-pull-request.*</span>81234</span>~s', $change);
        self::assertStringNotContainsString('#81234', $change);
    }

    public function testTheCategoriesAreATableWhoseRowsAddUp(): void
    {
        $rows = CategoryOverview::rows(self::buckets([
            ['key' => 'Form Framework', 'doc_count' => 4, 'tracker' => ['buckets' => [
                ['key' => 'Bug', 'doc_count' => 2],
                ['key' => 'Support', 'doc_count' => 1],
                ['key' => 'Topic', 'doc_count' => 1],
            ]]],
        ]));
        $without = CategoryOverview::withoutCategory(['doc_count' => 1, 'tracker' => ['buckets' => [
            ['key' => 'Bug', 'doc_count' => 1],
        ]]]);
        self::assertNotNull($without);
        $template = $this->twig->load('management/bycategory.html.twig');
        $html = $template->renderBlock('body', [
            'rows' => $rows, 'withoutCategory' => $without, 'sums' => CategoryOverview::sums([...$rows, $without]), 'trackers' => CategoryOverview::TRACKERS,
        ]);

        // No orange band, the title below the breadcrumb
        self::assertFalse($template->hasBlock('headline'));
        self::assertStringContainsString('<h1 class="page-intro-title">Issues by category</h1>', $html);
        // Every open issue, those without category, too
        self::assertStringContainsString('<strong>5</strong> open issues in <strong>1</strong> categories, the most open first.', $html);
        // The issues without category lead nowhere: the search has no filter for them
        // The issues without category lead to the search, too, with the value "Without category" of the filter
        self::assertMatchesRegularExpression('~<tr class="is-without">\\s*<th scope="row"><a href="/search\\?filters%5Bcategory%5D%5BWithout_category%5D=true" title="Show the open issues without category">Without category</a></th>\\s*<td class="is-count"><a href="/search\\?filters%5Bcategory%5D%5BWithout_category%5D=true&amp;filters%5Btracker%5D%5BBug%5D=true" title="Show the open Bug issues without category">1</a></td>~', $html);
        self::assertStringContainsString('<span class="tracker-dot is-story" aria-hidden="true"></span>Stories</th>', $html);
        self::assertStringContainsString('<a href="/search?filters%5Bcategory%5D%5BForm_Framework%5D=true&amp;filters%5Btracker%5D%5BBug%5D=true" title="Show the open Bug issues of Form Framework">2</a>', $html);
        // None as a dash, not as a link
        self::assertSame(9, substr_count($html, '<span class="count-table-none" aria-label="none">–</span>'));
        // The rare trackers together, the link finds the issues of each
        self::assertStringContainsString('filters%5Btracker%5D%5BSupport%5D=true&amp;filters%5Btracker%5D%5BTopic%5D=true" title="Show the open Support, Topic issues of Form Framework">2</a>', $html);
        self::assertMatchesRegularExpression('~<th scope="row">All open issues</th>\\s*<td class="is-count">3</td>(\\s*<td class="is-count">0</td>){4}\\s*<td class="is-count">2</td>\\s*<td class="is-count is-total">5</td>~', $html);
    }

    public function testStaleIssuesNameTheirChangesAPageAtATime(): void
    {
        $template = $this->twig->load('review/stale_issues.html.twig');
        $page = ['stages' => StaleIssues::STAGES, 'totalResults' => 30, 'startingAtItem' => 26, 'endingAtItem' => 28, 'pagesToLinkTo' => [0 => 1, 1 => 2], 'currentPage' => 2,
            'sort' => IssueSortEnum::PRIORITY, 'sortOptions' => IssueSortEnum::options(false), 'sortParameter' => ['sort' => 'priority']];
        $html = $template->renderBlock('body', $page + ['issues' => SearchResult::ofDocuments([
            new StaleIssue(
                Issue::fromDocument(['id' => 91089, 'status' => ['name' => 'Under Review']] + self::ISSUE),
                new ArrayCollection(),
            ),
            new StaleIssue(Issue::fromDocument(['id' => 91090] + self::ISSUE), new ArrayCollection([
                new LinkedChange(81234, ReviewStateEnum::MERGED),
                new LinkedChange(81240, ReviewStateEnum::ABANDONED),
                new LinkedChange(67256, null),
            ])),
            new StaleIssue(
                Issue::fromDocument(['id' => 91091, 'status' => ['name' => 'New']] + self::ISSUE),
                new ArrayCollection(),
            ),
        ], 30)]);

        self::assertFalse($template->hasBlock('headline'));
        self::assertStringContainsString('<h1 class="page-intro-title">Stale issues</h1>', $html);
        // The stages of ActivityEnum, in its words
        self::assertStringContainsString('<strong>30</strong> open issues without activity for 60 days or more: stale (60 to 180 days) or orphaned (more than 180 days).', $html);
        // The sort field of the issue lists, without relevance
        self::assertStringContainsString('<form class="list-sort" action="/issues/stale" method="get">', $html);
        self::assertStringContainsString('<option value="priority" selected>Priority</option>', $html);
        self::assertStringNotContainsString('value="relevance"', $html);
        self::assertStringContainsString('href="/issue/91089"', $html);
        self::assertStringContainsString('<span class="overview-list-item-note">Under review, but no change linked</span>', $html);
        self::assertStringContainsString('<span class="overview-list-item-note">Changes: 81234 merged · 81240 abandoned · 67256 not in Forger</span>', $html);
        // An issue not under review without a change needs no note
        self::assertSame(2, substr_count($html, 'overview-list-item-note'));
        // The count above, the pages below
        self::assertStringContainsString('Showing <strong>26</strong>–<strong>28</strong> of <strong>30</strong>', $html);
        self::assertStringContainsString('<li class="page-item active"><a class="page-link" href="/issues/stale?sort=priority&amp;page=2" aria-current="page">2</a></li>', $html);
        // The last page: back to the first and the previous one, nothing further
        self::assertStringContainsString('href="/issues/stale?sort=priority&amp;page=1" aria-label="Previous page"', $html);
        self::assertStringContainsString('<li class="page-item disabled"><span class="page-link" aria-label="Next page">', $html);
        self::assertLessThan(strpos($html, 'class="pagination"'), strpos($html, 'row-list'));

        $html = $template->renderBlock('body', ['stages' => StaleIssues::STAGES, 'totalResults' => 0, 'issues' => []]);
        self::assertStringContainsString('Every open issue had activity within the last 60 days.', $html);
        self::assertStringNotContainsString('row-list', $html);
    }

    public function testTheMonthsAsAHeatmapAndTheTrackersAsChips(): void
    {
        $rows = MonthOverview::rows(self::buckets([
            ['key_as_string' => '2025/01/01 00:00:00', 'doc_count' => 3, 'month' => ['buckets' => [
                ['key_as_string' => '2025/03/01 00:00:00', 'doc_count' => 3, 'status' => ['buckets' => [
                    ['key' => 'New', 'doc_count' => 2],
                    ['key' => 'Under Review', 'doc_count' => 1],
                ]]],
            ]]],
        ]));
        $template = $this->twig->load('management/index.html.twig');
        $html = $template->renderBlock('body', [
            'rows' => $rows, 'maximum' => 3, 'total' => 3, 'trackerFilter' => [],
            'trackers' => [
                ['tracker' => TrackerEnum::BUG, 'included' => true, 'toggle' => ['trackerFilters' => ['bug' => 'true']]],
                ['tracker' => TrackerEnum::STORY, 'included' => false, 'toggle' => ['trackerFilters' => []]],
            ],
        ]);

        self::assertFalse($template->hasBlock('headline'));
        self::assertStringContainsString('<h1 class="page-intro-title">Issues by month</h1>', $html);
        // A click leaves a tracker out or takes it in again
        self::assertStringContainsString('<a href="/issues/by-month?trackerFilters%5Bbug%5D=true" class="chip is-active" aria-pressed="true" title="Leave out the bugs">Bugs</a>', $html);
        self::assertStringContainsString('<a href="/issues/by-month" class="chip" aria-pressed="false" title="Take in the stories">Stories</a>', $html);
        // The darkest cell, its states on hover
        self::assertStringContainsString('<td class="is-count month-shade-5"><a href="/search?filters%5Bcreated%5D%5Brange%5D=2025-03-01%20to%202025-03-31" title="Mar 2025: New 2, Under Review 1">3</a></td>', $html);
        // The year and its total lead to the issues of the whole year
        self::assertStringContainsString('<th scope="row"><a href="/search?filters%5Bcreated%5D%5Brange%5D=2025-01-01%20to%202025-12-31" title="Show the open issues created in 2025">2025</a></th>', $html);
        self::assertStringContainsString('<td class="is-count is-total"><a href="/search?filters%5Bcreated%5D%5Brange%5D=2025-01-01%20to%202025-12-31" title="Show the open issues created in 2025">3</a></td>', $html);
        // With trackers left out the search finds those taken in
        $html = $template->renderBlock('body', ['trackerFilter' => ['Task' => 'true', 'Support' => 'true']] + compact('rows') + ['maximum' => 3, 'total' => 3, 'trackers' => []]);
        self::assertStringContainsString('href="/search?filters%5Bcreated%5D%5Brange%5D=2025-03-01%20to%202025-03-31&amp;filters%5Btracker%5D%5BTask%5D=true&amp;filters%5Btracker%5D%5BSupport%5D=true"', $html);
        self::assertSame(11, substr_count($html, '<td class="is-count month-shade-0"><span class="count-table-none" aria-label="none">–</span></td>'));
    }

    public function testTheFilterCreatedIsARangeAtTheEnd(): void
    {
        $render = fn (array $created): string => $this->twig->render('_partials/aggregations.html.twig', [
            'facetScope' => 'issues', 'dateFilters' => ['created' => ['title' => 'Created', 'value' => $created]],
            'aggregations' => ['Tracker' => Bucket::facetOf(['buckets' => [['key' => 'Bug', 'doc_count' => 2]]])],
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

    public function testTheFilterFileTakesAPath(): void
    {
        $render = fn (array $file): string => $this->twig->render('_partials/aggregations.html.twig', [
            'facetScope' => 'changes',
            'textFilters' => ['file' => ['title' => 'File', 'value' => $file, 'placeholder' => 'A path, or a folder']],
            'dateFilters' => ['updated' => ['title' => 'Updated', 'value' => []]],
            'aggregations' => ['Size' => Bucket::facetOf(['buckets' => [['key' => 'S', 'doc_count' => 2]]])],
        ]);

        $html = $render(['path' => 'typo3/sysext/core']);
        self::assertStringContainsString(
            '<forger-path-filter name="filters[file][path]" paths="typo3/sysext/core" input-id="facet-changes-file-value" placeholder="A path, or a folder">',
            $html,
        );
        self::assertStringContainsString('<label class="sr-only" for="facet-changes-file-value">Add to File</label>', $html);
        self::assertStringContainsString('id="facet-changes-file" class="collapse show"', $html);
        // After the filters to tick, before the dates
        self::assertGreaterThan(strpos($html, 'facet-changes-size'), strpos($html, 'facet-changes-file'));
        self::assertLessThan(strpos($html, 'facet-changes-updated'), strpos($html, 'facet-changes-file'));
        self::assertStringContainsString('id="facet-changes-file" class="collapse"', $render([]));
        self::assertStringContainsString('paths=""', $render([]));
    }

    public function testTheFilterFileHasEachPathOnce(): void
    {
        $html = $this->twig->render('_partials/aggregations.html.twig', [
            'facetScope' => 'changes',
            'textFilters' => ['file' => ['title' => 'File', 'value' => ['path' => ' /*.rst, typo3/sysext/core/*, *.rst']]],
            'aggregations' => [],
        ]);

        self::assertStringContainsString('paths="*.rst, typo3/sysext/core/*"', $html);
        // The icon of the chips, from the template bundle
        self::assertMatchesRegularExpression('#<template>\s*<span class="icon[^"]*">.*actions-close#s', $html);
    }

    public function testEveryFilterOfDatesHasTheDatepicker(): void
    {
        $html = $this->twig->render('_partials/aggregations.html.twig', [
            'facetScope' => 'changes',
            'dateFilters' => ['updated' => ['title' => 'Updated', 'value' => ['range' => '2026-11-01 to 2026-11-20']]],
            'aggregations' => ['Size' => Bucket::facetOf(['buckets' => [['key' => 'S', 'doc_count' => 2]]])],
        ]);

        self::assertStringContainsString('<span class="aggregation-title-text">Updated</span>', $html);
        self::assertStringContainsString('<label class="sr-only" for="facet-changes-updated-days">Updated from and to</label>', $html);
        self::assertStringContainsString('name="filters[updated][range]" value="2026-11-01 to 2026-11-20" placeholder="Pick the first and the last day" data-datepicker=', $html);
        // Clear empties its own field only
        self::assertStringContainsString('[name^=&quot;filters[updated]&quot;]', $html);
        self::assertGreaterThan(strpos($html, 'facet-changes-size'), strpos($html, 'facet-changes-updated'));
    }

    public function testNoPageHasTheOrangeBand(): void
    {
        // The template bundle shows the block "headline" in an orange band; base.html.twig shows "page_title" instead
        $withBand = [];
        foreach (new Finder()->files()->in(dirname(__DIR__, 2) . '/templates')->name('*.twig') as $file) {
            if (str_contains($file->getContents(), '{% block headline')) {
                $withBand[] = $file->getRelativePathname();
            }
        }
        self::assertSame([], $withBand);

        $base = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/base.html.twig');
        self::assertStringContainsString("<h1 class=\"page-intro-title\">{{ block('page_title') }}</h1>", $base);
    }

    public function testEverySearchHasTheSameField(): void
    {
        $field = static fn (string $html): bool => str_contains($html, '<form class="search-field');
        $html = $this->twig->render('_partials/search_form.html.twig', ['route' => 'search_search', 'query' => 'cache', 'searchClosed' => '1']);
        self::assertTrue($field($html));
        self::assertStringContainsString('name="searchClosed" value="1" checked', $html);

        // Small in the toolbar of a list, with the filters and the order of the list
        $html = $this->twig->createTemplate("{% import '_macros/search.html.twig' as search %}{{ search.field('/reviews/ready', 'cache', {'small': true, 'hidden': {'sort': 'smallest'}}) }}")->render();
        self::assertStringContainsString('<form class="search-field search-field-small" action="/reviews/ready"', $html);
        self::assertStringContainsString('<input type="hidden" name="sort" value="smallest">', $html);
        self::assertStringContainsString('class="btn btn-default btn-sm"', $html);
        // Without "closed" no checkbox
        self::assertStringNotContainsString('checkbox', $html);

        // The gray bar of before is gone
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/templates/_partials/searchbar.html.twig');
    }

    public function testBackportsShowTheVersionsOfEachChange(): void
    {
        $template = $this->twig->load('utility/backport.html.twig');
        $rows = new ArrayCollection([
            new BackportRow('I1', 81234, Change::fromDocument(self::CHANGE), new ArrayCollection([
                new BackportVersion('14.3', Change::fromDocument(['id' => 97836, 'branch' => '14.3'])),
                new BackportVersion('13.4', null),
            ])),
        ]);
        $html = $template->renderBlock('body', ['rows' => $rows, 'fileExists' => true]);

        // The change in main as in the lists, below it its versions
        self::assertStringContainsString('href="/change/81234"', $html);
        self::assertStringContainsString('<a class="status-pill status-pill-ready" href="/change/97836" title="97836 on 14.3: Ready for review">14.3 ready for review</a>', $html);
        self::assertStringContainsString('<span class="status-pill status-pill-backport-missing" title="No backport for 13.4 in Gerrit">13.4 missing</span>', $html);
        self::assertTrue($template->hasBlock('page_title'));
        $summary = $template->renderBlock('page_summary', ['rows' => $rows, 'fileExists' => true]);
        $summary = preg_replace('/\s+/', ' ', $summary);
        self::assertNotNull($summary);
        self::assertStringContainsString('<strong>1</strong> has a backport missing.', $summary);
        self::assertStringContainsString('No list of backports yet', $template->renderBlock('page_summary', ['rows' => [], 'fileExists' => false]));
    }

    public function testExactMatchesLeadToTheDetailPages(): void
    {
        $html = $this->twig->render('_partials/exact_matches.html.twig', ['exactMatches' => ['issue' => Issue::fromDocument(['id' => 81234] + self::ISSUE), 'change' => Change::fromDocument(self::CHANGE)]]);

        self::assertStringContainsString('href="/issue/81234"', $html);
        self::assertStringContainsString('href="/change/81234"', $html);
        self::assertSame('', trim($this->twig->render('_partials/exact_matches.html.twig', ['exactMatches' => ['issue' => null, 'change' => null]])));
    }

    public function testChangePage(): void
    {
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', [
            'change' => Change::fromDocument(self::CHANGE),
            'message' => "The cache is cleared.\n\nResolves: #91089\nRelated: #12",
            'issues' => new ArrayCollection([
                new RelatedIssue(91089, 'Resolves', Issue::fromDocument(self::ISSUE)),
                new RelatedIssue(12, 'Related', null),
            ]),
        ]);

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

    public function testAnOpenChangeSaysHowToTestIt(): void
    {
        $render = fn (array $change): string => $this->twig->load('change/show.html.twig')->renderBlock(
            'body',
            ['change' => Change::fromDocument($change), 'message' => '', 'issues' => []],
        );
        $fetch = 'git fetch https://review.typo3.org/Packages/TYPO3.CMS refs/changes/34/81234/3';

        $html = $render(self::CHANGE);
        self::assertStringContainsString('Test this change', $html);
        self::assertStringContainsString('<dt>Check out patch set 3</dt>', $html);
        self::assertStringContainsString($fetch . ' &amp;&amp; git checkout FETCH_HEAD', $html);
        self::assertStringContainsString('<dt>Cherry-pick onto main</dt>', $html);
        $cherryPick = $fetch . ' &amp;&amp; git cherry-pick FETCH_HEAD';
        self::assertStringContainsString('data-copy-text="' . $cherryPick . '"', $html);

        self::assertStringNotContainsString('Test this change', $render(['reviewstatus' => 'MERGED'] + self::CHANGE));
    }

    public function testAChangeLeadsToItsReviewAndItsFilesInGerrit(): void
    {
        $render = fn (array $change): string => $this->twig->load('change/show.html.twig')->renderBlock(
            'body',
            ['change' => Change::fromDocument($change), 'message' => '', 'issues' => []],
        );
        $patchSet = 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/81234/3';

        $html = $render(self::CHANGE + ['files' => ['typo3/sysext/core/Classes/Cache/CacheManager.php']]);
        self::assertStringContainsString('href="' . $patchSet . '//COMMIT_MSG"', $html);
        self::assertStringContainsString('Review in Gerrit', $html);
        self::assertStringContainsString(
            'href="' . $patchSet . '/typo3/sysext/core/Classes/Cache/CacheManager.php"',
            $html,
        );
        self::assertStringNotContainsString('Review in Gerrit', $render(['reviewstatus' => 'MERGED'] + self::CHANGE));
    }

    public function testTheFilesOfAChangeAreSortedAsATree(): void
    {
        $change = self::CHANGE + ['files' => ['composer.json', 'typo3/sysext/core/Classes/Cache.php', 'Build/Sources/cache.ts', 'typo3/sysext/backend/Classes/Page.php']];

        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument($change), 'message' => '', 'issues' => []]);

        preg_match_all('~<a href="/search/changes\?filters%5Bfile%5D%5Bpath%5D=[^"]+" title="([^"]+): the changes touching this file">~', $html, $matches);
        self::assertSame(['Build/Sources/cache.ts', 'typo3/sysext/backend/Classes/Page.php', 'typo3/sysext/core/Classes/Cache.php', 'composer.json'], $matches[1]);
    }

    public function testTheFactsOfAChangeLeadToItsFilters(): void
    {
        $change = ['releases' => ['main', '14.3']] + self::CHANGE;
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument($change), 'message' => '', 'issues' => []]);

        // As the checkboxes of the filters set them, spaces as "_"
        // The owner in the head and in the facts
        self::assertSame(2, substr_count($html, '<a href="/search/changes?filters%5Bowner%5D%5BBenni_Mack%5D=true" title="Changes by them">Benni Mack</a>'));
        // Every release a link of its own
        self::assertStringContainsString('<dd><a href="/search/changes?filters%5Breleases%5D%5Bmain%5D=true" title="Changes for this release">main</a>, <a href="/search/changes?filters%5Breleases%5D%5B14.3%5D=true" title="Changes for this release">14.3</a></dd>', $html);
        self::assertStringContainsString('<a href="/search/changes?filters%5Bpatchsets%5D%5B3%5D=true" title="Changes with as many patch sets">3</a>', $html);
        self::assertStringNotContainsString('searchClosed', $html);
        // Without releases no fact
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument(['releases' => []] + self::CHANGE), 'message' => '', 'issues' => []]);
        self::assertStringNotContainsString('Target releases', $html);

        // A merged change is among the changes its links find: they search the closed ones, too
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument(['reviewstatus' => 'MERGED'] + $change), 'message' => '', 'issues' => []]);
        self::assertStringContainsString('href="/search/changes?filters%5Bowner%5D%5BBenni_Mack%5D=true&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?filters%5Breleases%5D%5B14.3%5D=true&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?filters%5Bpatchsets%5D%5B3%5D=true&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?filters%5Btype%5D%5BBUGFIX%5D=true&amp;searchClosed=1"', $html);
        self::assertStringContainsString('href="/search/changes?query=caching&amp;searchClosed=1"', $html);
    }

    public function testTheFilesOfAChangeLeadToTheChangesTouchingThem(): void
    {
        $change = ['files' => ['typo3/sysext/core/Classes/Cache.php']] + self::CHANGE;
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument($change), 'message' => '', 'issues' => []]);
        self::assertStringContainsString('href="/search/changes?filters%5Bfile%5D%5Bpath%5D=typo3/sysext/core/Classes/Cache.php" title="typo3/sysext/core/Classes/Cache.php: the changes touching this file"', $html);

        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument(['reviewstatus' => 'ABANDONED'] + $change), 'message' => '', 'issues' => []]);
        self::assertStringContainsString('href="/search/changes?filters%5Bfile%5D%5Bpath%5D=typo3/sysext/core/Classes/Cache.php&amp;searchClosed=1"', $html);
    }

    public function testTheTagsOfAChangeLeadToTheSearch(): void
    {
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument(self::CHANGE), 'message' => '', 'issues' => []]);

        self::assertStringContainsString('<a class="tag-label tag-label-bugfix" href="/search/changes?filters%5Btype%5D%5BBUGFIX%5D=true" title="Bugfix: the changes with this tag">BUGFIX</a>', $html);
        // Not in the rows
        self::assertStringNotContainsString('<a class="tag-label', $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument(self::CHANGE), 'detailed' => true]));
    }

    public function testTheFilesAfterTheFirstTwentyAreCollapsed(): void
    {
        $files = array_map(static fn (int $number): string => sprintf('Classes/File%02d.php', $number), range(1, 23));
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument(self::CHANGE + ['files' => $files]), 'message' => '', 'issues' => []]);

        [$shown, $collapsed] = explode('<details class="detail-files-more">', $html);
        self::assertSame(20, substr_count($shown, ': the changes touching this file"'));
        self::assertStringContainsString('<summary>Show 3 more files</summary>', $collapsed);
        self::assertSame(3, substr_count($collapsed, ': the changes touching this file"'));
        // The order of the tree goes on in the collapsed ones
        self::assertStringContainsString('Classes/File20.php', $shown);
        self::assertStringContainsString('Classes/File21.php', $collapsed);

        // In one line: the folder may give way, the name not
        self::assertStringContainsString('<span class="detail-file-folder">Classes/</span><span class="detail-file-name">File01.php</span>', $shown);

        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument(self::CHANGE + ['files' => array_slice($files, 0, 20)]), 'message' => '', 'issues' => []]);
        self::assertStringNotContainsString('detail-files-more', $html);
    }

    public function testAChangeShowsEachLabelWithItsVotesItsFilesWithTheirLinesAndItsReviewLog(): void
    {
        $account = static fn (int $id, string $name): array => ['id' => $id, 'name' => $name, 'username' => null];
        $file = static fn (string $path, string $status, int $insertions, int $deletions): array => [
            'path' => $path,
            'status' => $status,
            'insertions' => $insertions,
            'deletions' => $deletions,
        ];
        $vote = static fn (string $label, int $value, int $id, string $name): array => [
            'label' => $label,
            'value' => $value,
            'account' => $account($id, $name),
            'date' => '2026/11/02 09:00:00',
        ];
        $message = static fn (int $id, string $name, string $text): array => [
            'author' => $account($id, $name),
            'date' => '2026/11/02 09:00:00',
            'patch_set' => 3,
            'message' => "Patch Set 3:\n\n" . $text,
        ];
        $change = self::CHANGE + [
            'files' => ['composer.json', 'typo3/sysext/core/Classes/Cache.php'],
            'file_details' => [
                $file('composer.json', 'modified', 2, 1),
                $file('typo3/sysext/core/Classes/Cache.php', 'added', 40, 0),
            ],
            'votes' => [$vote('Code-Review', 1, 7, 'Jane Doe'), $vote('Verified', -1, 49454, 'core-ci')],
            'label_states' => ['Code-Review' => 'NEED', 'Verified' => 'REJECT'],
            'conflicts' => ['composer.json'],
            'messages' => [$message(7, 'Jane Doe', 'Looks good.'), $message(49454, 'core-ci', 'Tests fail.')],
            'comment_count' => 4,
            'unresolved_comment_count' => 1,
            'cherry_pick_of' => 80000,
        ];
        $change['messages'][0]['id'] = 'm1';
        $change['comments'] = [[
            'id' => 'c1',
            'message_id' => 'm1',
            'file' => 'composer.json',
            'line' => 3,
            'unresolved' => true,
            'date' => '2026/11/02 09:00:00',
            'message' => 'Why this version?',
        ]];

        $html = $this->twig->load('change/show.html.twig')->renderBlock(
            'body',
            ['change' => Change::fromDocument($change), 'message' => '', 'issues' => []],
        );

        // The state of a label next to its vote, who voted below it, the CI apart
        self::assertStringContainsString(
            'status-pill-label-need" title="What Code review lacks for a merge">Needs a vote</span>',
            $html,
        );
        self::assertStringContainsString(
            'status-pill-label-reject" title="What Verified lacks for a merge">Blocked</span>',
            $html,
        );
        self::assertStringContainsString('Jane Doe', $html);
        self::assertStringContainsString('<li class="detail-voter is-ci">', $html);
        // The lines and the status of a file, the conflict marked
        self::assertStringContainsString(
            '<span class="is-added">+40</span> <span class="is-removed">&minus;0</span>',
            $html,
        );
        self::assertStringContainsString('<span class="detail-file-status">new</span>', $html);
        self::assertSame(1, substr_count($html, 'title="Conflict in the current patch set"'));
        // The review log in its order, the CI with a switch, the comment of a person in a box
        // Without the CI one message, with it two
        self::assertStringContainsString(
            'Review log <span class="detail-count timeline-without-bots">1</span>'
                . '<span class="detail-count timeline-with-bots">2</span>',
            $html,
        );
        self::assertStringContainsString('<span>Show the 1 message of the CI</span>', $html);
        self::assertSame(1, substr_count($html, '<li class="timeline-entry is-bot">'));
        self::assertLessThan(strpos($html, 'Tests fail.'), strpos($html, 'Looks good.'));
        self::assertStringContainsString('<div class="timeline-comment-text">Looks good.</div>', $html);
        self::assertStringContainsString('<span class="timeline-note">Tests fail.</span>', $html);
        self::assertStringContainsString('<span class="detail-fact-note">1 unresolved</span>', $html);
        // The comment under the message it was published with, open, and counted on its file
        self::assertStringContainsString('<code title="composer.json">composer.json:3</code>', $html);
        self::assertStringContainsString('<div class="timeline-comment-text">Why this version?</div>', $html);
        self::assertStringContainsString('<div class="timeline-comment is-open">', $html);
        self::assertStringContainsString('title="Comments on this file">', $html);
        self::assertStringContainsString('href="/change/80000">Change 80000</a>', $html);
    }

    public function testTheEarlierMessagesOfALongReviewLogAreOnAClick(): void
    {
        $messages = array_map(static fn (int $patchSet): array => [
            'author' => ['id' => 7, 'name' => 'Jane Doe'],
            'patch_set' => $patchSet,
            'message' => 'Uploaded patch set ' . $patchSet . '.',
        ], range(1, 30));

        $html = $this->twig->load('change/show.html.twig')->renderBlock(
            'body',
            [
                'change' => Change::fromDocument(self::CHANGE + ['messages' => $messages]),
                'message' => '',
                'issues' => [],
            ],
        );

        [$earlier, $recent] = explode('</details>', $html);
        self::assertStringContainsString(
            '<span class="timeline-earlier-label">Show 5 earlier messages</span>',
            $earlier,
        );
        self::assertSame(5, substr_count($earlier, '<li class="timeline-entry">'));
        self::assertSame(25, substr_count($recent, '<li class="timeline-entry">'));
        self::assertStringNotContainsString('timeline-bot-toggle', $html);
    }

    public function testAChangeShowsItsRelationChainWithTheRowsOfTheChangesForgerHas(): void
    {
        $chain = [
            ['number' => 81300, 'patch_set' => 1, 'current_patch_set' => 1, 'status' => 'NEW', 'subject' => 'Above'],
            ['number' => 81234, 'patch_set' => 2, 'current_patch_set' => 3, 'status' => 'NEW', 'subject' => 'This'],
            ['number' => 81100, 'patch_set' => 5, 'current_patch_set' => 5, 'status' => 'NEW', 'subject' => 'Below'],
        ];
        $above = Change::fromDocument(['id' => 81300, 'subject' => '[TASK] Above'] + self::CHANGE);

        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', [
            'change' => Change::fromDocument(self::CHANGE + ['chain' => $chain]),
            'message' => '',
            'issues' => [],
            'chain' => [81300 => $above],
        ]);

        self::assertStringContainsString('Relation chain <span class="detail-count">3</span>', $html);
        // Forger has the change above: its row; not the one below: its number and subject
        self::assertStringContainsString('href="/change/81300"', $html);
        self::assertStringContainsString('Based on this change', $html);
        self::assertStringContainsString('<span class="detail-number">81100</span>', $html);
        self::assertStringContainsString('This change is based on it', $html);
        self::assertStringContainsString('<li class="is-this">', $html);
        self::assertStringContainsString('The chain has patch set 2 of 3', $html);
        self::assertLessThan(strpos($html, '81100'), strpos($html, '81300'));

        $html = $this->twig->load('change/show.html.twig')->renderBlock(
            'body',
            ['change' => Change::fromDocument(self::CHANGE), 'message' => '', 'issues' => []],
        );
        self::assertStringNotContainsString('chain-title', $html);
    }

    public function testAChangeShowsTheChangesItConflictsWith(): void
    {
        $other = Change::fromDocument(['id' => 95192, 'subject' => '[TASK] Rework'] + self::CHANGE);

        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', [
            'change' => Change::fromDocument(self::CHANGE + ['merge_conflicts' => [95192, 94029]]),
            'message' => '',
            'issues' => [],
            'mergeConflicts' => [95192 => $other],
        ]);

        self::assertStringContainsString('Merge conflicts <span class="detail-count">2</span>', $html);
        self::assertStringContainsString('href="/change/95192"', $html);
        self::assertStringContainsString('Conflicts with this change', $html);
        // Not in Forger: its number leads to Gerrit
        self::assertStringContainsString('target="gerrit94029"', $html);
    }

    public function testAChangeOfAnOlderImportHasNoReviewLog(): void
    {
        $html = $this->twig->load('change/show.html.twig')->renderBlock(
            'body',
            ['change' => Change::fromDocument(self::CHANGE), 'message' => '', 'issues' => []],
        );

        self::assertStringNotContainsString('log-title', $html);
        self::assertStringContainsString('Imported before Forger kept the single votes', $html);
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
        // The author is "benni" to the index "user", see UserDirectory::keyOf()
        $cache = self::getContainer()->get('cache.app');
        $cache->delete('user_people');
        $cache->get('user_people', static fn (): array => ['keys' => ['forge:1' => 'benni'], 'people' => ['benni' => ['name' => 'Benni Mack', 'username' => 'benni', 'forge' => 1, 'gerrit' => null]]]);
        try {
            $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => Issue::fromDocument($issue), 'changes' => [], 'unknownChanges' => []]);
        } finally {
            $cache->delete('user_people');
        }

        // As the checkboxes of the search write the filters, a space as "_"; a person by the username
        self::assertStringContainsString('<a href="/search?filters%5Bcategory%5D%5BLink_Handling%5D=true" title="Issues in this category">Link Handling</a>', $html);
        self::assertStringContainsString('<a href="/search?filters%5Bauthor%5D%5Bbenni%5D=true" title="Issues by them">Benni Mack</a>', $html);
        // The author in the head too
        self::assertSame(2, substr_count($html, '<a href="/search?filters%5Bauthor%5D%5Bbenni%5D=true" title="Issues by them">Benni Mack</a>'));
        self::assertStringContainsString('href="/search?filters%5Btypo3_version%5D%5B14%5D=true"', $html);
        self::assertStringContainsString('<a class="tag-label" href="/search?filters%5Btags%5D%5Bpreview%5D=true" title="Issues with this tag">preview</a>', $html);
        // The search has no filter for it
        self::assertStringContainsString('<dd>easy</dd>', $html);
        // "-" is the value of the import for none: no fact, no "v-" in a row
        $none = ['typo3_version' => '-', 'php_version' => '-', 'complexity' => '-'] + self::ISSUE;
        $page = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => Issue::fromDocument($none), 'changes' => [], 'unknownChanges' => []]);
        self::assertStringNotContainsString('<dt>TYPO3 version</dt>', $page);
        self::assertStringNotContainsString('<dt>Complexity</dt>', $page);
        self::assertStringNotContainsString('title="TYPO3 version">v-', $this->twig->render('_partials/row/issue.html.twig', ['issue' => Issue::fromDocument($none), 'detailed' => true]));

        // Forger has only one project
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => Issue::fromDocument($issue + ['project' => ['id' => 27, 'name' => 'TYPO3 Core']]), 'changes' => [], 'unknownChanges' => []]);
        self::assertStringNotContainsString('<dt>Project</dt>', $html);
    }

    public function testIssuePage(): void
    {
        $journal = [
            'id' => 584271,
            'user' => ['name' => 'Gerrit Code Review'],
            'notes' => 'Pushed',
            'created_on' => '2026-11-02T10:00:00Z',
        ];
        $issue = Issue::fromDocument(self::ISSUE + ['journals' => [$journal]]);
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', [
            'issue' => $issue,
            'history' => $issue->history(),
            'changes' => [],
            'unknownChanges' => [81234],
        ]);

        // Without possible duplicates there is no button to them
        self::assertStringNotContainsString('href="/issue/91089/duplicates"', $html);
        self::assertStringContainsString('href="https://forge.typo3.org/issues/91089"', $html);
        self::assertStringContainsString('Change 81234</a>', $html);
        self::assertStringContainsString('Gerrit Code Review', $html);
        // The time of an entry is the markup of relativetime(), not its escaped text
        self::assertStringContainsString('<span data-processor="relativetime"', $html);
        self::assertStringNotContainsString('&lt;span', $html);
    }

    public function testTheTooltipOfAnActivityPillIsPlainText(): void
    {
        $html = $this->twig->createTemplate(
            "{% import '_macros/status.html.twig' as status %}{{ status.activityPill(stage, updatedOn, createdOn) }}",
        )->render([
            'stage' => ActivityEnum::STALE,
            'updatedOn' => '2026/11/02 10:00:00',
            'createdOn' => '2026/10/01 08:30:00',
        ]);

        self::assertMatchesRegularExpression('/ · updated 2026-11-02 \d\d:00 · created 2026-10-01 \d\d:30"/', $html);
        self::assertStringNotContainsString('&lt;span', $html);
    }

    public function testIssuePageWithoutChanges(): void
    {
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', ['issue' => Issue::fromDocument(self::ISSUE), 'changes' => [], 'unknownChanges' => []]);

        self::assertStringContainsString('No change resolves or names this issue', $html);
        self::assertStringContainsString('href="https://forge.typo3.org/issues/91089/edit"', $html);
        self::assertStringContainsString('Fix this issue', $html);
        self::assertStringContainsString(
            '<code class="detail-command-text">[BUGFIX] Something is broken'
                . "\n\nResolves: #91089\nReleases: main</code>",
            $html,
        );
    }

    public function testOnlyAnOpenIssueWithoutAnOpenChangeSaysHowToFixIt(): void
    {
        $render = fn (array $issue, array $changes): string => $this->twig->load('issue/show.html.twig')->renderBlock(
            'body',
            [
                'issue' => Issue::fromDocument($issue),
                'changes' => new ArrayCollection($changes),
                'unknownChanges' => [],
            ],
        );
        $abandonedChange = Change::fromDocument(['reviewstatus' => 'ABANDONED'] + self::CHANGE);
        $abandoned = new RelatedChange($abandonedChange, 'Resolves');
        $open = new RelatedChange(Change::fromDocument(self::CHANGE), 'Resolves');

        $html = $render(self::ISSUE, [$abandoned]);
        self::assertStringContainsString('Fix this issue', $html);
        self::assertStringContainsString('The changes above were merged or abandoned', $html);
        self::assertStringContainsString('status-pill-abandoned', $html);
        self::assertStringNotContainsString('Fix this issue', $render(self::ISSUE, [$open]));
        self::assertStringNotContainsString(
            'Fix this issue',
            $render(['status' => ['id' => 5, 'name' => 'Closed']] + self::ISSUE, []),
        );
    }

    public function testEachEntryOfTheHistoryLeadsToItOnForge(): void
    {
        $journal = [
            'id' => 584554,
            'user' => ['name' => 'Benni Mack'],
            'notes' => 'Works for me',
            'created_on' => '2026-11-02T10:00:00Z',
        ];
        $issue = Issue::fromDocument(self::ISSUE + ['journals' => [$journal]]);
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', [
            'issue' => $issue,
            'history' => $issue->history(),
            'changes' => [],
            'unknownChanges' => [],
        ]);

        self::assertStringContainsString('href="https://forge.typo3.org/issues/91089#change-584554"', $html);
    }

    public function testIssuePageTellsHowAChangeBelongsToIt(): void
    {
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', [
            'issue' => Issue::fromDocument(self::ISSUE),
            'changes' => new ArrayCollection([new RelatedChange(Change::fromDocument(self::CHANGE), 'Resolves')]),
            'unknownChanges' => [],
        ]);

        self::assertStringContainsString('<span class="overview-list-relation">Resolves this issue</span>', $html);
        self::assertStringContainsString('href="/change/81234"', $html);
    }

    public function testListsLeadToTheDetailPages(): void
    {
        self::assertStringContainsString('href="/change/81234"', $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument(self::CHANGE), 'detailed' => true]));
        self::assertStringContainsString('href="/issue/91089"', $this->twig->render('_partials/row/issue.html.twig', ['issue' => Issue::fromDocument(self::ISSUE), 'detailed' => true]));
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

    public function testTheDuplicatesStandInGroupsWithTheirSimilarityBeside(): void
    {
        $groups = new DuplicateGroups(
            marked: [new DuplicateHit(self::issue(80001), null, relation: DuplicateGroups::DUPLICATES)],
            similar: [DuplicateHit::checked(self::issue(91100), 50, 34)],
        );
        $html = $this->twig->load('issue/duplicates.html.twig')->renderBlock('body', [
            'issue' => Issue::fromDocument(self::ISSUE),
            'groups' => $groups,
        ]);

        self::assertStringContainsString(
            '<h1 class="detail-title">Possible duplicates <span class="detail-count">2</span></h1>',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~<h2 id="duplicates-compared" class="detail-section-title">Compared with</h2>.*href="/issue/91089".*'
            . 'Steps to reproduce.*Marked on Forge~s',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~Marked on Forge.*href="/issue/80001".*This issue duplicates it~s',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~Similar issues <span class="detail-count">1</span>.*href="/issue/91100"~s',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~<span class="duplicates-score-value">42 %</span>\s*'
            . '<span class="duplicates-score-meter" aria-hidden="true"><span style="width: 42%"></span></span>~',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~<span class="duplicates-score-directions" title="Seen from #91089: 50 %. Seen from #91100: 34 %.">'
            . '50 % · 34 %\s*</span>~',
            $html,
        );
        self::assertStringContainsString('Only issues with 50 % or more are listed.', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testTheListOfPossibleDuplicatesShowsEachPairWithBothIssues(): void
    {
        $rows = DuplicatePairs::rows(
            [new DuplicatePair(80001, 91100, 45, 30, 60, '2026/11/21 10:00:00')],
            new ArrayCollection([80001 => self::issue(80001), 91100 => self::issue(91100)]),
        );
        $html = $this->twig->load('issue/duplicate_pairs.html.twig')->renderBlock(
            'body',
            (new Pagination(1, 1))->viewData() + ['rows' => $rows],
        );

        self::assertStringContainsString('<strong>1</strong> pairs of open issues with 50 % or more', $html);
        self::assertMatchesRegularExpression(
            '~<span class="duplicates-score-value">45 %</span>.*Seen from #91100: 60 %. Seen from #80001: 30 %.~s',
            $html,
        );
        self::assertMatchesRegularExpression('~href="/issue/91100/duplicates">.*Compare</a>~s', $html);
        // The newer one first, it is the likely duplicate
        self::assertMatchesRegularExpression('~href="/issue/91100".*href="/issue/80001"~s', $html);
    }

    public function testAPairWhoseIssueLeftTheIndexIsLeftOut(): void
    {
        $rows = DuplicatePairs::rows(
            [new DuplicatePair(80001, 91100, 45, 30, 60, '2026/11/21 10:00:00')],
            new ArrayCollection([91100 => self::issue(91100)]),
        );

        self::assertSame([], $rows);
    }

    public function testTheIssuePageShowsItsParentSubtasksAndRelatedIssuesApart(): void
    {
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', [
            'issue' => Issue::fromDocument(self::ISSUE),
            'changes' => [],
            'unknownChanges' => [],
            'parent' => new RelatedIssue(90000, Relations::PARENT, self::issue(90000)),
            'subtasks' => new ArrayCollection([self::issue(91100), self::issue(91200)]),
            'relatedIssues' => new ArrayCollection([new RelatedIssue(80004, 'Blocked by', null)]),
        ]);

        self::assertMatchesRegularExpression(
            '~<dt>Parent task</dt>\s*<dd><a href="/issue/90000">#90000 Something is broken</a></dd>~',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~Subtasks <span class="detail-count">2</span>.*href="/issue/91100".*href="/issue/91200"'
            . '.*Related issues <span class="detail-count">1</span>~s',
            $html,
        );
        // Not in Forger: a link to Forge
        self::assertMatchesRegularExpression('~href="https://forge.typo3.org/issues/80004".*Blocked by~s', $html);
    }

    public function testAnIssueWithoutRelationsHasNoSectionsForThem(): void
    {
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', [
            'issue' => Issue::fromDocument(self::ISSUE),
            'changes' => [],
            'unknownChanges' => [],
        ]);

        self::assertStringNotContainsString('Subtasks', $html);
        self::assertStringNotContainsString('Related issues', $html);
        self::assertStringNotContainsString('Parent task', $html);
        self::assertStringNotContainsString('attachments-title', $html);
    }

    public function testTheIssuePageListsItsFilesWithALinkToForge(): void
    {
        $html = $this->twig->load('issue/show.html.twig')->renderBlock('body', [
            'issue' => Issue::fromDocument(self::ISSUE + ['attachments' => [[
                'id' => 38811,
                'filename' => 'crop.png',
                'filesize' => 48213,
                'content_type' => 'image/png',
                'description' => '',
                'content_url' => 'https://forge.typo3.org/attachments/download/38811/crop.png',
                'author' => ['id' => 36415, 'name' => 'Jane Doe'],
                'created_on' => '2026-11-20T10:05:00Z',
            ]]]),
            'changes' => [],
            'unknownChanges' => [],
        ]);

        self::assertStringContainsString('Files <span class="detail-count">1</span>', $html);
        self::assertMatchesRegularExpression(
            '~href="https://forge.typo3.org/attachments/download/38811/crop.png".*crop.png.*47 KB.*Jane Doe~s',
            $html,
        );
    }

    public function testTheIssuePageShowsTheDuplicatesButtonOnlyWithDuplicates(): void
    {
        $render = fn (DuplicateGroups $duplicates): string => $this->twig->load('issue/show.html.twig')->renderBlock(
            'body',
            [
                'issue' => Issue::fromDocument(self::ISSUE),
                'changes' => [],
                'unknownChanges' => [],
                'duplicates' => $duplicates,
            ],
        );
        $hits = static fn (int ...$ids): array => array_values(array_map(
            static fn (int $id): DuplicateHit => new DuplicateHit(self::issue($id), 50),
            $ids,
        ));

        $html = $render(new DuplicateGroups($hits(80001), $hits(91100, 91200)));
        self::assertMatchesRegularExpression(
            '~<a class="btn btn-sm btn-primary duplicates-button" href="/issue/91089/duplicates">.*'
            . 'Possible duplicates <span class="detail-count">3</span>\s*</a>~s',
            $html,
        );
        self::assertStringNotContainsString('similar issue', $html);
        self::assertStringNotContainsString('/issue/91089/duplicates', $render(new DuplicateGroups()));
    }

    public function testWithoutDuplicatesThePageSaysSo(): void
    {
        $html = $this->twig->load('issue/duplicates.html.twig')->renderBlock('body', [
            'issue' => Issue::fromDocument(self::ISSUE),
            'groups' => new DuplicateGroups(),
        ]);

        self::assertStringContainsString('No open issue is much like this one.', $html);
    }

    private static function issue(int $id): Issue
    {
        return Issue::fromDocument(['id' => $id] + self::ISSUE);
    }

    public function testDetailPagesHaveABreadcrumb(): void
    {
        $change = $this->twig->load('change/show.html.twig')->renderBlock('breadcrumb', ['change' => Change::fromDocument(self::CHANGE)]);
        $issue = $this->twig->load('issue/duplicates.html.twig')->renderBlock('breadcrumb', ['issue' => Issue::fromDocument(self::ISSUE)]);

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
        $html = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument(['mergeable' => 'no'] + self::CHANGE)]);

        // The pill alone, without a bar beside the row
        self::assertStringNotContainsString('has-conflict', $html);
        self::assertStringContainsString('>Merge conflict</span>', $html);
        self::assertStringNotContainsString('Merge conflict', $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument(self::CHANGE)]));
    }

    public function testTheChangePageNamesAMergeConflict(): void
    {
        $html = $this->twig->load('change/show.html.twig')->renderBlock('body', ['change' => Change::fromDocument(['mergeable' => 'no'] + self::CHANGE), 'message' => '', 'issues' => []]);

        self::assertStringContainsString('<p class="detail-notice detail-notice-danger" role="status">', $html);
        self::assertStringContainsString('It needs a rebase onto main.', $html);
    }

    public function testListsShowTheSize(): void
    {
        $html = $this->twig->render('_partials/row/change.html.twig', ['change' => Change::fromDocument(self::CHANGE + ['insertions' => 40, 'deletions' => 20]), 'detailed' => true]);

        self::assertStringContainsString('status-pill-size-normal" title="Normal: +40 −20 lines, up to 250 lines changed">Normal</span>', $html);
    }

    /**
     * @param list<array<string, mixed>> $buckets
     *
     * @return ArrayCollection<int, Bucket>
     */
    private static function buckets(array $buckets): ArrayCollection
    {
        return new ArrayCollection(array_map(
            static fn (array $bucket): Bucket => Bucket::fromArray($bucket),
            $buckets,
        ));
    }
}
