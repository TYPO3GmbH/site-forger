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
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/**
 * The guide for agents at /llms.txt, rendered with strict variables.
 */
final class LlmsTxtTemplateTest extends KernelTestCase
{
    private string $text;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        self::assertTrue($twig->isStrictVariables());
        $this->text = $twig->render('static_page/llms.txt.twig');
    }

    public function testFollowsTheFormatOfLlmsTxt(): void
    {
        self::assertStringStartsWith("# Forger\n\n> ", $this->text);
        self::assertStringContainsString("\n## API\n", $this->text);
        self::assertStringContainsString("\n## Optional\n", $this->text);
        // Plain text: the template escapes nothing
        self::assertStringNotContainsString('&amp;', $this->text);
        self::assertStringNotContainsString('&#', $this->text);
    }

    public function testLinksEveryEndpointOfTheApi(): void
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $home = rtrim($router->generate('dashboard_index', [], RouterInterface::ABSOLUTE_URL), '/');

        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, 'api_v1_')) {
                $link = '(' . $home . $route->getPath() . ')';
                self::assertStringContainsString($link, $this->text, $name . ' is missing');
            }
        }
    }
}
