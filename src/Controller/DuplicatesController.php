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
use App\Report\DuplicatePairs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The open issues that may be duplicates of each other, for the triage.
 */
class DuplicatesController extends AbstractController
{
    public function __construct(
        private readonly DuplicatePairs $pairs,
    ) {
    }

    #[Route(path: '/issues/duplicates', name: 'issues_duplicates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = new Pagination($request->query->getInt('page', 1));
        $found = $this->pairs->page($page->from(), $page->perPage);

        return $this->render('issue/duplicate_pairs.html.twig', $page->withTotal($found->total)->viewData() + [
            'rows' => $found,
        ]);
    }
}
