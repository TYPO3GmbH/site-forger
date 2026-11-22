<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Api\ActivityFilters;
use App\Api\ActivityMapper;
use App\Api\ChangeFilters;
use App\Api\ChangeMapper;
use App\Api\InvalidParameter;
use App\Api\IssueFilters;
use App\Api\IssueMapper;
use App\Api\ListParameters;
use App\Api\Model\ActivityCalendar;
use App\Api\Model\ActivityClosedYears;
use App\Api\Model\ActivityExchange;
use App\Api\Model\ActivityNewcomers;
use App\Api\Model\ActivityPage;
use App\Api\Model\ActivityStatistics;
use App\Api\Model\ActivityWeekHours;
use App\Api\Model\ChangeDetail;
use App\Api\Model\ChangePage;
use App\Api\Model\DuplicateList;
use App\Api\Model\Error;
use App\Api\Model\IssueDetail;
use App\Api\Model\IssuePage;
use App\Document\Relations;
use App\Duplicates\DuplicateGroups;
use App\Duplicates\DuplicateSearch;
use App\Elastic\Bucket;
use App\Enum\ChangeListEnum;
use App\Enum\ChangeStateFilterEnum;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStateFilterEnum;
use App\Enum\ReviewSortEnum;
use App\Enum\StreamIntervalEnum;
use App\Enum\StreamSplitEnum;
use App\People\UserDirectory;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Repository\StreamRepository;
use App\Search\ApiChangeQuery;
use App\Search\ApiIssueQuery;
use App\Search\IssueSearch;
use App\Search\StreamQuery;
use App\Stream\NewcomerFinder;
use App\Stream\StreamStatistics;
use Doctrine\Common\Collections\ArrayCollection;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public API: the issues and changes Forger indexed, read only and without a login.
 *
 * The attributes describe it for the documentation at /api/doc, see config/packages/nelmio_api_doc.yaml. The
 * objects it returns are the classes in App\Api\Model. ApiRateLimitSubscriber limits the requests and adds the
 * headers for caching and for other sites.
 */
#[Route(path: '/api/v1', name: 'api_v1_', methods: ['GET'], format: 'json', stateless: true)]
class ApiV1Controller extends AbstractController
{
    private const string PERSON = 'A number is the user on Forge, as "author.id" of an issue has it. Words are '
        . 'searched in the name, in any case, and may fit several people: the issues tell which.';

    private const string BAD_REQUEST = 'A parameter has a value the endpoint does not accept.';

    private const string TOO_MANY_REQUESTS = 'Too many requests from the address. The header "Retry-After" says in '
        . 'how many seconds the next one is accepted.';

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly ChangeRepository $changes,
        private readonly Relations $relations,
        private readonly DuplicateSearch $duplicateSearch,
        private readonly IssueMapper $issueMapper,
        private readonly ChangeMapper $changeMapper,
        private readonly StreamRepository $stream,
        private readonly StreamStatistics $streamStatistics,
        private readonly NewcomerFinder $newcomerFinder,
        private readonly ActivityMapper $activityMapper,
        private readonly UserDirectory $users,
    ) {
    }

    #[Route(path: '/issues', name: 'issues')]
    #[OA\Get(
        operationId: 'listIssues',
        description: 'Finds Forge issues by words and filters. Without parameters it lists the open issues, the ones '
            . 'updated last first.',
        summary: 'List and search issues',
        tags: ['Issues'],
    )]
    #[OA\Parameter(
        name: 'q',
        description: 'Search words, or a whole text. A few words are searched in what Forge shows of an issue, with '
            . 'stems: "caching" also finds "cache", a number finds its issue. A text of more than '
            . IssueSearch::TEXT_WORDS . ' words, like a report, finds the issues with the most similar wording, as '
            . 'the duplicates do: paste a report to find out whether somebody already filed it. With words the '
            . 'default order is "relevance".',
        in: 'query',
        schema: new OA\Schema(type: 'string'),
        example: 'image cache',
    )]
    #[OA\Parameter(
        name: 'status',
        description: 'Which issues to list by their status. "closed" covers Resolved, Closed and Rejected.',
        in: 'query',
        schema: new OA\Schema(type: 'string', default: 'open', enum: IssueStateFilterEnum::class),
    )]
    #[OA\Parameter(
        name: 'tracker',
        description: 'Only issues of this tracker, by its exact name, like "Bug" or "Feature".',
        in: 'query',
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Parameter(
        name: 'category',
        description: 'Only issues of this category, by its exact name, like "Backend User Interface". "none" for '
            . 'the issues without a category, which are many.',
        in: 'query',
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Parameter(
        name: 'author',
        description: 'Only issues this person reported. ' . self::PERSON,
        in: 'query',
        schema: new OA\Schema(type: 'string'),
        example: 'wolters',
    )]
    #[OA\Parameter(
        name: 'assignee',
        description: 'Only issues assigned to this person. On an old issue the assignee is often who touched it '
            . 'last, not who works on it. ' . self::PERSON,
        in: 'query',
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Parameter(
        name: 'involving',
        description: 'Only issues this person reported or is assigned to. ' . self::PERSON,
        in: 'query',
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Parameter(
        name: 'createdSince',
        description: 'Only issues reported on this day or later, as YYYY-MM-DD in UTC.',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date'),
    )]
    #[OA\Parameter(
        name: 'createdBefore',
        description: 'Only issues reported before this day, as YYYY-MM-DD in UTC.',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date'),
    )]
    #[OA\Parameter(
        name: 'updatedSince',
        description: 'Only issues changed on this day or later, as YYYY-MM-DD in UTC.',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date'),
    )]
    #[OA\Parameter(
        name: 'updatedBefore',
        description: 'Only issues nobody changed since this day, as YYYY-MM-DD in UTC: the ones everybody walked '
            . 'past.',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date'),
    )]
    #[OA\Parameter(
        name: 'breakdown',
        description: 'Also count how all issues of the search spread by status, tracker, category and year, not '
            . 'only those of the page.',
        in: 'query',
        schema: new OA\Schema(type: 'boolean', default: false),
    )]
    #[OA\Parameter(
        name: 'sort',
        description: 'The order of the issues. The default is "relevance" with search words and "updated" without '
            . 'them.',
        in: 'query',
        schema: new OA\Schema(type: 'string', enum: IssueSortEnum::class),
    )]
    #[OA\Parameter(ref: '#/components/parameters/limit')]
    #[OA\Parameter(ref: '#/components/parameters/offset')]
    #[OA\Response(response: 200, description: 'One page of issues.', content: new Model(type: IssuePage::class))]
    #[OA\Response(response: 400, description: self::BAD_REQUEST, content: new Model(type: Error::class))]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function issues(Request $request): JsonResponse
    {
        try {
            $list = ListParameters::fromRequest($request);
            $breakdown = ListParameters::flag($request, 'breakdown');
            $search = ApiIssueQuery::request(
                $list,
                IssueFilters::fromRequest($request),
                ListParameters::choice($request, 'sort', IssueSortEnum::class, self::issueSort($list)),
                $breakdown,
            );
        } catch (InvalidParameter $e) {
            return self::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        $found = $this->issues->search($search);

        return self::respond(new IssuePage(
            $found->total,
            $list->offset,
            $list->limit,
            array_values($found->map($this->issueMapper->summary(...))->toArray()),
            $breakdown ? $this->issueMapper->breakdown($found) : null,
        ));
    }

    #[Route(path: '/issues/{id}', name: 'issue', requirements: ['id' => '\d{1,9}'])]
    #[OA\Get(
        operationId: 'getIssue',
        description: 'One Forge issue with its description, its relations and the Gerrit changes that belong to it.',
        summary: 'Get an issue',
        tags: ['Issues'],
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'The number of the issue on Forge.',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer', minimum: 1),
        example: 105403,
    )]
    #[OA\Response(response: 200, description: 'The issue.', content: new Model(type: IssueDetail::class))]
    #[OA\Response(
        response: 404,
        description: 'Forger does not have the issue.',
        content: new Model(type: Error::class),
    )]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function issue(int $id): JsonResponse
    {
        $issue = $this->issues->byId($id);
        if (null === $issue) {
            return self::issueNotFound($id);
        }

        return self::respond($this->issueMapper->detail(
            $issue,
            $this->relations->changesOf($issue),
            $this->relations->subtasksOf($issue),
            $this->relations->indexedRelatedIssues($issue),
        ));
    }

    #[Route(path: '/issues/{id}/duplicates', name: 'issue_duplicates', requirements: ['id' => '\d{1,9}'])]
    #[OA\Get(
        operationId: 'listDuplicates',
        description: 'The open issues that may describe the same as the issue: first those Forge relates as '
            . 'duplicates, then those whose wording is at least half the same, as on the duplicates page of Forger. '
            . 'Ask it before you report or fix something, to find a report nobody linked.',
        summary: 'Find the duplicates of an issue',
        tags: ['Issues'],
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'The number of the issue on Forge.',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer', minimum: 1),
        example: 105403,
    )]
    #[OA\Response(
        response: 200,
        description: 'The possible duplicates.',
        content: new Model(type: DuplicateList::class),
    )]
    #[OA\Response(
        response: 404,
        description: 'Forger does not have the issue.',
        content: new Model(type: Error::class),
    )]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function issueDuplicates(int $id): JsonResponse
    {
        $issue = $this->issues->byId($id);
        if (null === $issue) {
            return self::issueNotFound($id);
        }
        $groups = DuplicateGroups::of(
            $issue,
            $this->duplicateSearch->find($issue->id),
            $this->issues->byIds($issue->duplicateNumbers()),
        );

        return self::respond($this->issueMapper->duplicates($issue, $groups));
    }

    #[Route(path: '/changes', name: 'changes')]
    #[OA\Get(
        operationId: 'listChanges',
        description: 'Finds Gerrit changes of Packages/TYPO3.CMS by words and filters. Without parameters it lists '
            . 'the open changes, the ones updated last first.',
        summary: 'List and search changes',
        tags: ['Changes'],
    )]
    #[OA\Parameter(
        name: 'q',
        description: 'Search words. They are searched in the subject and the commit message. A number also finds '
            . 'the change with that number and the changes that resolve or name the issue with it. A Change-Id '
            . 'finds its change, a topic its changes, a name the changes of its owner.',
        in: 'query',
        schema: new OA\Schema(type: 'string'),
        example: 'site configuration',
    )]
    #[OA\Parameter(
        name: 'status',
        description: 'Which changes to list by their status. "open" covers the changes ready for review and those '
            . 'in progress.',
        in: 'query',
        schema: new OA\Schema(type: 'string', default: 'open', enum: ChangeStateFilterEnum::class),
    )]
    #[OA\Parameter(
        name: 'branch',
        description: 'Only changes for this branch, like "main" or "13.4". "master" is the same as "main".',
        in: 'query',
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Parameter(
        name: 'list',
        description: 'Only the open changes of a review list, as the review pages of Forger have them: "ready" has no '
            . 'negative vote and no conflict, "needs-review" is verified without a positive review, '
            . '"needs-verification" the other way round, "verified" has both, "ready-to-merge" has both and no '
            . 'negative vote or conflict, "wip" is work in progress.',
        in: 'query',
        schema: new OA\Schema(type: 'string', enum: ChangeListEnum::class),
    )]
    #[OA\Parameter(
        name: 'path',
        description: 'Only changes whose current patch set touches this file, or a file in this folder, as the path '
            . 'in the repository, like "typo3/sysext/core/Classes/Cache". A "*" stands for any characters, "/" '
            . 'included: "*.rst" finds the changes of any reST file, "typo3/sysext/*/Configuration/*" the '
            . 'configuration of all system extensions. Several paths are separated by commas, a change touches each '
            . 'of them: "*.rst,typo3/sysext/install" finds the changes of the install tool with documentation.',
        in: 'query',
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Parameter(
        name: 'owner',
        description: 'Only changes this person uploaded. A number is the account on Gerrit, as "owner.id" of a '
            . 'change has it. Words are searched in the name, in any case, and may fit several people.',
        in: 'query',
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Parameter(
        name: 'maxLines',
        description: 'Only changes that add and remove at most this many lines together: the quick reviews.',
        in: 'query',
        schema: new OA\Schema(type: 'integer', maximum: ChangeFilters::MAX_LINES, minimum: 0),
    )]
    #[OA\Parameter(
        name: 'createdSince',
        description: 'Only changes uploaded on this day or later, as YYYY-MM-DD in UTC.',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date'),
    )]
    #[OA\Parameter(
        name: 'createdBefore',
        description: 'Only changes uploaded before this day, as YYYY-MM-DD in UTC.',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date'),
    )]
    #[OA\Parameter(
        name: 'updatedSince',
        description: 'Only changes that changed on this day or later, as YYYY-MM-DD in UTC.',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date'),
    )]
    #[OA\Parameter(
        name: 'updatedBefore',
        description: 'Only changes nothing happened to since this day, as YYYY-MM-DD in UTC.',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date'),
    )]
    #[OA\Parameter(
        name: 'sort',
        description: 'The order of the changes. The default is "relevance" with search words and "updated" without '
            . 'them. "smallest" sorts by the lines added and removed together.',
        in: 'query',
        schema: new OA\Schema(type: 'string', enum: ReviewSortEnum::class),
    )]
    #[OA\Parameter(ref: '#/components/parameters/limit')]
    #[OA\Parameter(ref: '#/components/parameters/offset')]
    #[OA\Response(response: 200, description: 'One page of changes.', content: new Model(type: ChangePage::class))]
    #[OA\Response(response: 400, description: self::BAD_REQUEST, content: new Model(type: Error::class))]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function changes(Request $request): JsonResponse
    {
        try {
            $list = ListParameters::fromRequest($request);
            $search = ApiChangeQuery::request(
                $list,
                ChangeFilters::fromRequest($request),
                ListParameters::choice($request, 'sort', ReviewSortEnum::class, self::changeSort($list)),
            );
        } catch (InvalidParameter $e) {
            return self::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        $found = $this->changes->search($search);

        return self::respond(new ChangePage(
            $found->total,
            $list->offset,
            $list->limit,
            array_values($found->map($this->changeMapper->summary(...))->toArray()),
        ));
    }

    #[Route(path: '/changes/{number}', name: 'change', requirements: ['number' => '\d{1,9}'])]
    #[OA\Get(
        operationId: 'getChange',
        description: 'One Gerrit change with its commit message, its files, its review log, its relation chain and '
            . 'the Forge issues it names.',
        summary: 'Get a change',
        tags: ['Changes'],
    )]
    #[OA\Parameter(
        name: 'number',
        description: 'The number of the change on Gerrit.',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer', minimum: 1),
        example: 97865,
    )]
    #[OA\Response(response: 200, description: 'The change.', content: new Model(type: ChangeDetail::class))]
    #[OA\Response(
        response: 404,
        description: 'Forger does not have the change.',
        content: new Model(type: Error::class),
    )]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function change(int $number): JsonResponse
    {
        $change = $this->changes->byNumber($number);
        if (null === $change) {
            return self::error(sprintf('Change %d is not in Forger.', $number), Response::HTTP_NOT_FOUND);
        }

        return self::respond($this->changeMapper->detail(
            $change,
            $this->relations->issuesOf($change),
            $change->hasChain() ? $this->relations->chainOf($change) : new ArrayCollection(),
            $this->relations->mergeConflictsOf($change),
        ));
    }

    private static function issueSort(ListParameters $list): IssueSortEnum
    {
        return IssueSortEnum::default('' !== $list->query);
    }

    private static function changeSort(ListParameters $list): ReviewSortEnum
    {
        return ReviewSortEnum::default('' !== $list->query);
    }

    private static function issueNotFound(int $id): JsonResponse
    {
        return self::error(sprintf('Issue #%d is not in Forger.', $id), Response::HTTP_NOT_FOUND);
    }

    #[Route(path: '/activity', name: 'activity')]
    #[OA\Get(
        operationId: 'listActivity',
        description: 'What happened on Forge and in Gerrit, the newest first: an issue reported, commented, changed '
            . 'or closed, a change pushed, voted on, merged or abandoned. Without parameters it lists the last seven '
            . 'days, without the events of tools.',
        summary: 'List the activity',
        tags: ['Activity'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/activityRange')]
    #[OA\Parameter(ref: '#/components/parameters/activityFrom')]
    #[OA\Parameter(ref: '#/components/parameters/activityTo')]
    #[OA\Parameter(ref: '#/components/parameters/activitySince')]
    #[OA\Parameter(ref: '#/components/parameters/activityTimeZone')]
    #[OA\Parameter(ref: '#/components/parameters/activitySource')]
    #[OA\Parameter(ref: '#/components/parameters/activityKind')]
    #[OA\Parameter(ref: '#/components/parameters/activityBranch')]
    #[OA\Parameter(ref: '#/components/parameters/activityPerson')]
    #[OA\Parameter(ref: '#/components/parameters/activityPeople')]
    #[OA\Parameter(ref: '#/components/parameters/activityBots')]
    #[OA\Parameter(ref: '#/components/parameters/limit')]
    #[OA\Parameter(ref: '#/components/parameters/offset')]
    #[OA\Response(response: 200, description: 'One page of events.', content: new Model(type: ActivityPage::class))]
    #[OA\Response(response: 400, description: self::BAD_REQUEST, content: new Model(type: Error::class))]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function activity(Request $request): JsonResponse
    {
        try {
            $list = ListParameters::fromRequest($request);
            $filters = ActivityFilters::fromRequest($request, $this->users);
        } catch (InvalidParameter $e) {
            return self::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        $events = $this->stream->search(
            StreamQuery::requestWithBranches($filters->filter, $list->offset, $list->limit),
        );

        return self::respond($this->activityMapper->page(
            $events,
            $list,
            $filters,
            Bucket::facetOf($events->aggregation(StreamQuery::BRANCHES)),
        ));
    }

    #[Route(path: '/activity/statistics', name: 'activity_statistics')]
    #[OA\Get(
        operationId: 'getActivityStatistics',
        description: 'Counts the activity of the filter: the key figures, the events per hour, day or week, and per '
            . 'weekday and hour, all in "timeZone". Without parameters it counts the last seven days in UTC, without '
            . 'the events of tools.',
        summary: 'Count the activity',
        tags: ['Activity'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/activityRange')]
    #[OA\Parameter(ref: '#/components/parameters/activityFrom')]
    #[OA\Parameter(ref: '#/components/parameters/activityTo')]
    #[OA\Parameter(ref: '#/components/parameters/activitySince')]
    #[OA\Parameter(ref: '#/components/parameters/activityTimeZone')]
    #[OA\Parameter(ref: '#/components/parameters/activitySource')]
    #[OA\Parameter(ref: '#/components/parameters/activityKind')]
    #[OA\Parameter(ref: '#/components/parameters/activityBranch')]
    #[OA\Parameter(ref: '#/components/parameters/activityPerson')]
    #[OA\Parameter(ref: '#/components/parameters/activityPeople')]
    #[OA\Parameter(ref: '#/components/parameters/activityBots')]
    #[OA\Parameter(
        name: 'interval',
        description: 'The length of a bucket over time. One that does not fit the window, see "intervals" of the '
            . 'window, leaves the one by the length of the window.',
        in: 'query',
        schema: new OA\Schema(type: 'string', enum: StreamIntervalEnum::class),
    )]
    #[OA\Parameter(
        name: 'split',
        description: 'How the events over time are split: one series for all, one per channel, or one per kind.',
        in: 'query',
        schema: new OA\Schema(type: 'string', default: 'total', enum: StreamSplitEnum::class),
    )]
    #[OA\Response(
        response: 200,
        description: 'The statistics of the activity.',
        content: new Model(type: ActivityStatistics::class),
    )]
    #[OA\Response(response: 400, description: self::BAD_REQUEST, content: new Model(type: Error::class))]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function activityStatistics(Request $request): JsonResponse
    {
        try {
            $filters = ActivityFilters::fromRequest($request, $this->users);
            $split = ActivityFilters::split($request);
        } catch (InvalidParameter $e) {
            return self::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        $insights = $this->streamStatistics->insights($filters->filter, $filters->window, $split);

        return self::respond($this->activityMapper->statistics($insights, $filters));
    }

    #[Route(path: '/activity/newcomers', name: 'activity_newcomers')]
    #[OA\Get(
        operationId: 'getActivityNewcomers',
        description: 'Lists the firsts of new people in the time window, the newest first: their first issue on '
            . 'Forge, their first change in Gerrit, the first change of theirs that was merged. And where each '
            . 'stands now: so a mentor sees who could use help. Without parameters it covers the last seven days in '
            . 'UTC.',
        summary: 'List the new contributors',
        tags: ['Activity'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/activityRange')]
    #[OA\Parameter(ref: '#/components/parameters/activityFrom')]
    #[OA\Parameter(ref: '#/components/parameters/activityTo')]
    #[OA\Parameter(ref: '#/components/parameters/activitySince')]
    #[OA\Parameter(ref: '#/components/parameters/activityTimeZone')]
    #[OA\Response(
        response: 200,
        description: 'The new contributors.',
        content: new Model(type: ActivityNewcomers::class),
    )]
    #[OA\Response(response: 400, description: self::BAD_REQUEST, content: new Model(type: Error::class))]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function activityNewcomers(Request $request): JsonResponse
    {
        try {
            $filters = ActivityFilters::fromRequest($request, $this->users);
        } catch (InvalidParameter $e) {
            return self::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return self::respond($this->activityMapper->newcomers($this->newcomerFinder->find($filters->window), $filters));
    }

    #[Route(path: '/activity/week-hours', name: 'activity_week_hours')]
    #[OA\Get(
        operationId: 'getActivityWeekHours',
        description: 'Counts the activity of the filter per weekday and hour in "timeZone": when people are active. '
            . 'Without parameters it counts the last seven days in UTC.',
        summary: 'Count the activity per weekday and hour',
        tags: ['Activity'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/activityRange')]
    #[OA\Parameter(ref: '#/components/parameters/activityYear')]
    #[OA\Parameter(ref: '#/components/parameters/activityFrom')]
    #[OA\Parameter(ref: '#/components/parameters/activityTo')]
    #[OA\Parameter(ref: '#/components/parameters/activitySince')]
    #[OA\Parameter(ref: '#/components/parameters/activityTimeZone')]
    #[OA\Parameter(ref: '#/components/parameters/activitySource')]
    #[OA\Parameter(ref: '#/components/parameters/activityKind')]
    #[OA\Parameter(ref: '#/components/parameters/activityBranch')]
    #[OA\Parameter(ref: '#/components/parameters/activityPerson')]
    #[OA\Parameter(ref: '#/components/parameters/activityPeople')]
    #[OA\Parameter(ref: '#/components/parameters/activityBots')]
    #[OA\Response(
        response: 200,
        description: 'The activity per weekday and hour.',
        content: new Model(type: ActivityWeekHours::class),
    )]
    #[OA\Response(response: 400, description: self::BAD_REQUEST, content: new Model(type: Error::class))]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function activityWeekHours(Request $request): JsonResponse
    {
        try {
            $filters = ActivityFilters::fromRequest($request, $this->users);
        } catch (InvalidParameter $e) {
            return self::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return self::respond($this->activityMapper->weekHours(
            $this->streamStatistics->weekHours($filters->filter),
            $filters,
        ));
    }

    #[Route(path: '/activity/days', name: 'activity_days')]
    #[OA\Get(
        operationId: 'getActivityDays',
        description: 'Counts the activity of the filter per day of a year in "timeZone": when in the year people '
            . 'work. Without "year" it counts the last 12 months.',
        summary: 'Count the activity per day of a year',
        tags: ['Activity'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/activityYear')]
    #[OA\Parameter(ref: '#/components/parameters/activityTimeZone')]
    #[OA\Parameter(ref: '#/components/parameters/activitySource')]
    #[OA\Parameter(ref: '#/components/parameters/activityKind')]
    #[OA\Parameter(ref: '#/components/parameters/activityBranch')]
    #[OA\Parameter(ref: '#/components/parameters/activityPerson')]
    #[OA\Parameter(ref: '#/components/parameters/activityPeople')]
    #[OA\Parameter(ref: '#/components/parameters/activityBots')]
    #[OA\Response(
        response: 200,
        description: 'The activity per day.',
        content: new Model(type: ActivityCalendar::class),
    )]
    #[OA\Response(response: 400, description: self::BAD_REQUEST, content: new Model(type: Error::class))]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function activityDays(Request $request): JsonResponse
    {
        try {
            // A year: the one given, else the last 12 months
            $query = array_diff_key($request->query->all(), array_flip(['from', 'to', 'since']));
            $request = $request->duplicate([...$query, 'range' => '1y']);
            $filters = ActivityFilters::fromRequest($request, $this->users);
        } catch (InvalidParameter $e) {
            return self::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return self::respond($this->activityMapper->calendar(
            $this->streamStatistics->days($filters->filter, $filters->window),
            $filters,
        ));
    }

    #[Route(path: '/activity/exchange', name: 'activity_exchange')]
    #[OA\Get(
        operationId: 'getActivityExchange',
        description: 'Counts the reviews between a person and the others in the time window: the votes and comments '
            . 'of the person on the changes of others, the ones of others on the changes of the person, and the '
            . 'closest partners of each. Also how long the changes of the person waited, beside all changes. It needs '
            . '"person" or "people". Without a time window it covers the last seven days in UTC.',
        summary: 'Count the reviews between a person and the others',
        tags: ['Activity'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/activityRange')]
    #[OA\Parameter(ref: '#/components/parameters/activityFrom')]
    #[OA\Parameter(ref: '#/components/parameters/activityTo')]
    #[OA\Parameter(ref: '#/components/parameters/activitySince')]
    #[OA\Parameter(ref: '#/components/parameters/activityTimeZone')]
    #[OA\Parameter(ref: '#/components/parameters/activityPerson')]
    #[OA\Parameter(ref: '#/components/parameters/activityPeople')]
    #[OA\Response(
        response: 200,
        description: 'The reviews between the person and the others.',
        content: new Model(type: ActivityExchange::class),
    )]
    #[OA\Response(response: 400, description: self::BAD_REQUEST, content: new Model(type: Error::class))]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function activityExchange(Request $request): JsonResponse
    {
        try {
            // A person nobody has counts nothing, as "people" finds nothing then
            if (!$request->query->has('person') && !$request->query->has('people')) {
                throw InvalidParameter::needsOneOf('person', 'people');
            }
            $filters = ActivityFilters::fromRequest($request, $this->users);
        } catch (InvalidParameter $e) {
            return self::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return self::respond($this->activityMapper->exchange(
            $this->streamStatistics->exchange($filters->filter, $filters->window),
            $filters,
        ));
    }

    #[Route(path: '/activity/closed-years', name: 'activity_closed_years')]
    #[OA\Get(
        operationId: 'getActivityClosedYears',
        description: 'Counts the issues and the changes closed in this year and the two before, summed up month by '
            . 'month: so the years can be compared. It takes no filter.',
        summary: 'Count what was closed over the years',
        tags: ['Activity'],
    )]
    #[OA\Response(
        response: 200,
        description: 'What was closed per year.',
        content: new Model(type: ActivityClosedYears::class),
    )]
    #[OA\Response(response: 429, description: self::TOO_MANY_REQUESTS, content: new Model(type: Error::class))]
    public function activityClosedYears(): JsonResponse
    {
        $years = $this->streamStatistics->closedYears(new \DateTimeImmutable());

        return self::respond(ActivityMapper::closedYears($years));
    }

    private static function error(string $message, int $status): JsonResponse
    {
        return self::respond(new Error($message), $status);
    }

    private static function respond(object $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $response;
    }
}
