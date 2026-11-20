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
use App\Elastic\ElasticUtility;
use App\Elastic\GlobalSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A single Gerrit change, as Forger indexed it.
 */
class ChangeController extends AbstractController
{
    #[Route(path: '/change/{number}', name: 'change_show', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function show(int $number): Response
    {
        $change = GlobalSearch::document('review', $number)
            ?? throw $this->createNotFoundException(sprintf('Change %d is not in Forger. It may be newer than the last import.', $number));

        // The issues of the footer, in its order: those it resolves first
        $relations = [];
        foreach ([CommitMessage::RESOLVES => $change['resolves'] ?? [], CommitMessage::RELATED => $change['related'] ?? []] as $relation => $ids) {
            foreach ($ids as $id) {
                $relations[(int) $id] ??= $relation;
            }
        }
        $found = [];
        if ([] !== $relations) {
            $results = ElasticUtility::getIndexByName('issue')->search([
                'query' => ['terms' => ['id' => array_keys($relations)]],
                'size' => count($relations),
            ])->getResults();
            foreach ($results as $result) {
                $found[(int) $result->getData()['id']] = $result->getData();
            }
        }
        $issues = [];
        foreach ($relations as $id => $relation) {
            // Not imported: in another project, or private
            $issues[] = ['id' => $id, 'relation' => $relation, 'issue' => $found[$id] ?? null];
        }

        return $this->render('change/show.html.twig', [
            'change' => $change,
            'message' => CommitMessage::body((string) ($change['commit_message'] ?? '')),
            'issues' => $issues,
        ]);
    }
}
