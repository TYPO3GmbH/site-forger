<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\CommitMessage;
use App\Elastic\GerritLinks;
use App\Elastic\GlobalSearch;
use App\Elastic\Indices;
use App\Enum\IssueStatusEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A single Forge issue, as Forger indexed it.
 */
#[Route(path: '/issue/{id}', requirements: ['id' => '\d+'])]
class IssueController extends AbstractController
{
    /**
     * How many likely duplicates the duplicates page shows, the most similar first.
     */
    private const int DUPLICATES = 25;

    /**
     * How many changes of an issue its page shows at most, the ones updated last first.
     */
    private const int CHANGES = 50;

    public function __construct(
        private readonly Indices $indices,
        private readonly GlobalSearch $globalSearch,
    ) {
    }

    #[Route(name: 'issue_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $issue = $this->issue($id);
        $numbers = GerritLinks::changeNumbers($issue);
        // The changes whose footer resolves or names the issue, and those its comments link
        $results = $this->indices->reviews()->search([
            'query' => ['bool' => ['should' => [
                ['term' => ['resolves' => $id]],
                ['term' => ['related' => $id]],
                ['terms' => ['id' => [] === $numbers ? [0] : $numbers]],
            ]]],
            'sort' => [['updated_on' => 'desc']],
            'size' => self::CHANGES,
        ])->getResults();
        $changes = [];
        foreach ($results as $result) {
            $change = $result->getData();
            $changes[] = ['change' => $change, 'relation' => match (true) {
                in_array($id, $change['resolves'] ?? [], true) => CommitMessage::RESOLVES,
                in_array($id, $change['related'] ?? [], true) => CommitMessage::RELATED,
                default => 'Linked',
            }];
        }

        return $this->render('issue/show.html.twig', [
            'issue' => $issue,
            'changes' => $changes,
            // Linked in a comment, but not imported: closed long ago, or in another project
            'unknownChanges' => array_values(array_diff($numbers, array_map(static fn (array $change): int => (int) $change['change']['id'], $changes))),
        ]);
    }

    /**
     * The open issues most like this one, all issues with "searchClosed".
     */
    #[Route(path: '/duplicates', name: 'issue_duplicates', methods: ['GET'])]
    public function duplicates(Request $request, int $id): Response
    {
        $issue = $this->issue($id);
        $searchClosed = 1 === $request->query->getInt('searchClosed');
        $bool = [
            'must' => [[
                'more_like_this' => [
                    'fields' => ['subject', 'description'],
                    'like' => [['_id' => (string) $id]],
                    'min_term_freq' => 2,
                    'max_query_terms' => 25,
                    'minimum_should_match' => '50%',
                ],
            ]],
        ];
        if (!$searchClosed) {
            $bool['must_not'] = [['terms' => ['status.name' => IssueStatusEnum::closedLabels()]]];
        }
        $results = $this->indices->issues()->search(['query' => ['bool' => $bool], 'size' => self::DUPLICATES]);

        return $this->render('issue/duplicates.html.twig', [
            'issue' => $issue,
            'results' => $results->getResults(),
            'totalResults' => $results->getTotalHits(),
            'searchClosed' => $searchClosed,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(int $id): array
    {
        return $this->globalSearch->document(Indices::ISSUE, $id)
            ?? throw $this->createNotFoundException(sprintf('Issue #%d is not in Forger. It may be newer than the last import.', $id));
    }
}
