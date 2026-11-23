<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\DTO\Pagination;
use App\Enum\IssueSortEnum;
use App\Enum\TrackerEnum;
use App\Search\IssueListSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Report an issue: the open issues that match the words come first, so that a known problem gets a comment and no
 * duplicate. The form of Forge for a new issue comes after them.
 */
class ReportIssueController extends AbstractController
{
    public const int MATCHES = 5;

    public function __construct(
        private readonly IssueListSearch $issueListSearch,
    ) {
    }

    #[Route(path: '/report', name: 'report_issue', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = trim($request->query->getString('query'));
        $kind = 'feature' === $request->query->getString('kind') ? 'feature' : 'bug';
        $matches = null;
        if ('' !== $query) {
            $matches = $this->issueListSearch->search(
                $query,
                false,
                IssueSortEnum::RELEVANCE,
                [],
                new Pagination(1, perPage: self::MATCHES),
            )->results;
        }

        return $this->render('report_issue/index.html.twig', [
            'query' => $query,
            'kind' => $kind,
            'tracker' => 'feature' === $kind ? TrackerEnum::FEATURE : TrackerEnum::BUG,
            'matches' => $matches,
        ]);
    }
}
