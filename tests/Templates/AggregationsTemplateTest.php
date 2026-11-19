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
 * The filters of a list: which of them the server opens, and the ids the template bundle remembers them by.
 */
final class AggregationsTemplateTest extends KernelTestCase
{
    private const array AGGREGATIONS = [
        'Tracker' => ['buckets' => [['key' => 'Bug', 'doc_count' => 3], ['key' => 'Task', 'doc_count' => 2]]],
        'Target Version' => ['buckets' => [['key' => '14.0', 'doc_count' => 1]]],
    ];

    public function testIdsNameTheFilterAndTheKindOfList(): void
    {
        $html = $this->render('/search?query=cache', 'issues');

        self::assertStringContainsString('id="facet-issues-tracker"', $html);
        self::assertStringContainsString('href="#facet-issues-target_version"', $html);
        // Not by position: the second filter of another list is another filter
        self::assertStringNotContainsString('collapse-2', $html);
        self::assertStringContainsString('id="facet-changes-tracker"', $this->render('/gerrit/status', 'changes'));
    }

    public function testWithoutSelectedValuesEveryFilterStartsClosed(): void
    {
        $html = $this->render('/search?query=cache', 'issues');

        self::assertSame(0, substr_count($html, 'collapse show'));
        self::assertSame(2, substr_count($html, 'aria-expanded="false"'));
        self::assertStringNotContainsString('data-bs-parent="#aggregations" data-facet-active', $html);
    }

    public function testAFilterWithSelectedValuesIsOpen(): void
    {
        $html = $this->render('/search?query=cache&filters[tracker][Bug]=true', 'issues');

        self::assertStringContainsString('<a class="aggregation-title-link" data-bs-toggle="collapse" href="#facet-issues-tracker" aria-expanded="true"', $html);
        self::assertStringContainsString('id="facet-issues-tracker" class="collapse show"', $html);
        self::assertStringContainsString('data-bs-parent="#aggregations" data-facet-active', $html);
        self::assertStringContainsString('id="facet-issues-target_version" class="collapse"', $html);
        self::assertStringContainsString('1 / 2', $html);
    }

    private function render(string $uri, string $scope): string
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->get('request_stack')->push(Request::create($uri));
        /** @var Environment $twig */
        $twig = $container->get('twig');

        return $twig->render('_partials/aggregations.html.twig', ['aggregations' => self::AGGREGATIONS, 'facetScope' => $scope, 'query' => '']);
    }
}
