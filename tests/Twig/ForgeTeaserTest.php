<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Twig;

use App\Document\Issue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ForgeTeaserTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    public function testPlainTextWithoutMarkupAndCode(): void
    {
        $teaser = $this->teaser("h2. Steps to reproduce\n\n# Open the *page* module\n# Select <b>it</b>\n\n<pre>\n\$GLOBALS['TCA'];\n</pre>\n\nThe @default@ language & more.");

        self::assertSame('Steps to reproduce Open the page module Select it The default language & more.', html_entity_decode($teaser));
        self::assertStringNotContainsString('TCA', $teaser);
        self::assertStringNotContainsString('<b>', $teaser);
    }

    public function testLongTextIsCutAtAWord(): void
    {
        $teaser = html_entity_decode($this->teaser(str_repeat('word ', 100)));

        self::assertStringEndsWith('word…', $teaser);
        self::assertLessThanOrEqual(241, mb_strlen($teaser));
    }

    public function testNoDescriptionIsEmpty(): void
    {
        self::assertSame('', $this->teaser(null));
        self::assertSame('', $this->teaser("  \n "));
    }

    public function testTheSearchRowShowsTheTeaser(): void
    {
        $issue = [
            'id' => 101234,
            'subject' => 'Something is broken',
            'description' => 'The *page* module fails.',
            'created_on' => '2026/11/01 10:00:00',
            'updated_on' => '2026/11/02 10:00:00',
        ];

        $row = $this->twig->render('_partials/row/issue.html.twig', ['issue' => Issue::fromDocument($issue), 'size' => 'l']);
        self::assertStringContainsString('<span class="overview-list-item-teaser">The page module fails.</span>', $row);

        // Not in the short rows of the dashboard and the detail pages
        $row = $this->twig->render('_partials/row/issue.html.twig', ['issue' => Issue::fromDocument($issue)]);
        self::assertStringNotContainsString('overview-list-item-teaser', $row);

        unset($issue['description']);
        $row = $this->twig->render('_partials/row/issue.html.twig', ['issue' => Issue::fromDocument($issue), 'size' => 'l']);
        self::assertStringNotContainsString('overview-list-item-teaser', $row);
    }

    private function teaser(?string $description): string
    {
        return $this->twig->createTemplate('{{ description|forgeTeaser }}')->render(['description' => $description]);
    }
}
