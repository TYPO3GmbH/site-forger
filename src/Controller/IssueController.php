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
use App\Duplicates\DuplicateGroups;
use App\Duplicates\DuplicateSearch;
use App\Repository\IssueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly DuplicateSearch $duplicateSearch,
    ) {
    }

    #[Route(name: 'issue_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $issue = $this->issue($id);
        $changes = $this->relations->changesOf($issue);

        return $this->render('issue/show.html.twig', [
            'issue' => $issue,
            'duplicates' => $this->duplicateGroups($issue),
            'changes' => $changes,
            // Linked in a comment, but not imported: closed long ago, or in another project
            'unknownChanges' => Relations::unknownChanges($issue, $changes),
        ]);
    }

    /**
     * The open issues most like this one. The issues Forge relates as duplicates come first.
     */
    #[Route(path: '/duplicates', name: 'issue_duplicates', methods: ['GET'])]
    public function duplicates(int $id): Response
    {
        $issue = $this->issue($id);

        return $this->render('issue/duplicates.html.twig', [
            'issue' => $issue,
            'groups' => $this->duplicateGroups($issue),
        ]);
    }

    private function duplicateGroups(Issue $issue): DuplicateGroups
    {
        $related = $this->issues->byIds($issue->duplicateNumbers());

        return DuplicateGroups::of($issue, $this->duplicateSearch->find($issue->id), $related);
    }

    private function issue(int $id): Issue
    {
        $message = sprintf('Issue #%d is not in Forger. It may be newer than the last import.', $id);

        return $this->issues->byId($id) ?? throw $this->createNotFoundException($message);
    }
}
