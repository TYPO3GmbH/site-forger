<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Api\ChangeFilters;
use App\Api\ChangeMapper;
use App\Api\InvalidParameter;
use App\Api\IssueFilters;
use App\Api\IssueMapper;
use App\Api\ListParameters;
use App\Api\Model\ChangeDetail;
use App\Api\Model\ChangePage;
use App\Api\Model\DuplicateList;
use App\Api\Model\Error;
use App\Api\Model\IssueDetail;
use App\Api\Model\IssuePage;
use App\Document\Relations;
use App\Duplicates\DuplicateGroups;
use App\Duplicates\DuplicateSearch;
use App\Enum\ChangeListEnum;
use App\Enum\ChangeStateFilterEnum;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStateFilterEnum;
use App\Enum\ReviewSortEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\ApiChangeQuery;
use App\Search\ApiIssueQuery;
use App\Search\IssueSearch;
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

    #[Route(path: '/issues/{id}', name: 'issue', requirements: ['id' => '\d+'])]
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

    #[Route(path: '/issues/{id}/duplicates', name: 'issue_duplicates', requirements: ['id' => '\d+'])]
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
            . 'in the repository, like "typo3/sysext/core/Classes/Cache".',
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

    #[Route(path: '/changes/{number}', name: 'change', requirements: ['number' => '\d+'])]
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
