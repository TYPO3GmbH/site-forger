<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Document\Issue;
use App\Document\Relations;
use App\Repository\IssueRepository;
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
    public function __construct(
        private readonly Relations $relations,
        private readonly IssueRepository $issues,
    ) {
    }

    #[Route(name: 'issue_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $issue = $this->issue($id);
        $changes = $this->relations->changesOf($issue);

        return $this->render('issue/show.html.twig', [
            'issue' => $issue,
            'changes' => $changes,
            // Linked in a comment, but not imported: closed long ago, or in another project
            'unknownChanges' => Relations::unknownChanges($issue, $changes),
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
        $results = $this->relations->duplicatesOf($id, $searchClosed);

        return $this->render('issue/duplicates.html.twig', [
            'issue' => $issue,
            'results' => $results,
            'searchClosed' => $searchClosed,
        ]);
    }

    private function issue(int $id): Issue
    {
        $message = sprintf('Issue #%d is not in Forger. It may be newer than the last import.', $id);

        return $this->issues->byId($id) ?? throw $this->createNotFoundException($message);
    }
}
