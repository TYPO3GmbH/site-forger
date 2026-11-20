<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The tabs of the search take the people of the filters along, see GlobalSearch::tabFilters(); the other filters
 * belong to one tab, assets/js/search-tabs.js gives them back.
 */
final class SearchTabsTemplateTest extends KernelTestCase
{
    public function testTheAssigneeOfTheIssuesIsTheOwnerOfTheChanges(): void
    {
        $html = $this->render('/search?query=cache&filters[assigned_to][Benni_Mack]=true&filters[status][New]=true', 'issues');

        self::assertStringContainsString('data-tab="changes" data-people="owner" href="/search/changes?query=cache&amp;filters%5Bowner%5D%5BBenni_Mack%5D=true"', $html);
        // The tab shown links to itself as a new search
        self::assertStringContainsString('data-tab="issues" data-people="author assigned_to" href="/search?query=cache" aria-current="page"', $html);
        self::assertStringContainsString('data-active="issues"', $html);
    }

    public function testTheOwnerOfTheChangesIsTheAuthorOfTheIssues(): void
    {
        $html = $this->render('/search/changes?filters[owner][Benni_Mack]=true&filters[size][Easy]=true&searchClosed=1', 'changes');

        self::assertStringContainsString('data-tab="issues" data-people="author assigned_to" href="/search?query=&amp;searchClosed=1&amp;filters%5Bauthor%5D%5BBenni_Mack%5D=true"', $html);
    }

    private function render(string $uri, string $active): string
    {
        self::bootKernel();
        $container = self::getContainer();
        $request = Request::create($uri);
        $container->get('request_stack')->push($request);
        /** @var Environment $twig */
        $twig = $container->get('twig');

        return $twig->render('_partials/search_tabs.html.twig', [
            'active' => $active,
            'tabs' => ['issues' => 3, 'changes' => 2],
            'query' => $request->query->getString('query'),
            'searchClosed' => '1' === $request->query->getString('searchClosed') ? '1' : null,
        ]);
    }
}
