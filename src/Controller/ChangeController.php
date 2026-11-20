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
use App\Elastic\GlobalSearch;
use App\Elastic\Indices;
use App\Elastic\Relations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A single Gerrit change, as Forger indexed it.
 */
class ChangeController extends AbstractController
{
    public function __construct(
        private readonly Relations $relations,
        private readonly GlobalSearch $globalSearch,
    ) {
    }

    #[Route(path: '/change/{number}', name: 'change_show', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function show(int $number): Response
    {
        $message = sprintf('Change %d is not in Forger. It may be newer than the last import.', $number);
        $change = $this->globalSearch->document(Indices::REVIEW, $number);
        if (null === $change) {
            throw $this->createNotFoundException($message);
        }

        return $this->render('change/show.html.twig', [
            'change' => $change,
            'message' => CommitMessage::body((string) ($change['commit_message'] ?? '')),
            'issues' => $this->relations->issuesOf($change),
        ]);
    }
}
