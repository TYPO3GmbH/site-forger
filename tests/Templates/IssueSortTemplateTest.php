<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Enum\IssueSortEnum;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The sort field of the issue lists, rendered with strict variables.
 */
final class IssueSortTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
        self::assertTrue($this->twig->isStrictVariables());
    }

    public function testSortFieldSendsTheParametersOfTheList(): void
    {
        $template = $this->twig->createTemplate(
            "{% import '_macros/sort.html.twig' as sorting %}{{ sorting.select('/search', sort, options, parameters) }}"
        );
        $html = $template->render([
            'sort' => IssueSortEnum::NEWEST,
            'options' => IssueSortEnum::options(true),
            'parameters' => ['query' => 'cache', 'filters' => ['tracker' => ['Bug' => 'true']], 'searchClosed' => null],
        ]);

        self::assertStringContainsString('<input type="hidden" name="query" value="cache">', $html);
        self::assertStringContainsString('<input type="hidden" name="filters[tracker][Bug]" value="true">', $html);
        self::assertStringNotContainsString('searchClosed', $html);
        self::assertStringContainsString('<option value="newest" selected>Newest</option>', $html);
        self::assertStringContainsString('<option value="relevance">Relevance</option>', $html);
    }
}
