<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Twig\ForgerTwigExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The tags in the head for search engines and the previews of shared links.
 */
final class MetaTagsTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    public function testThePreviewHasTheTitleTheDescriptionTheLinkAndTheImage(): void
    {
        $html = $this->twig->render('_partials/meta_tags.html.twig', [
            'title' => 'Report an issue - Forger',
            'description' => 'Report a bug & an idea',
            'url' => 'https://forger.typo3.com/report',
            'image' => 'https://forger.typo3.com/images/forger-share.png',
        ]);

        self::assertStringContainsString('<meta name="description" content="Report a bug &amp; an idea">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://forger.typo3.com/report">', $html);
        self::assertStringContainsString('<meta property="og:title" content="Report an issue - Forger">', $html);
        self::assertStringContainsString(
            '<meta property="og:image" content="https://forger.typo3.com/images/forger-share.png">',
            $html,
        );
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function testABlockOfAPageBecomesOneLineOfPlainText(): void
    {
        $block = "\n    Bug · New.\n    <b>Don&#039;t</b> crop &amp; scale\n";

        $extension = self::getContainer()->get(ForgerTwigExtension::class);

        self::assertSame("Bug · New. Don't crop & scale", $extension->metaText($block));
    }

    public function testTheShareImageExists(): void
    {
        $size = getimagesize(__DIR__ . '/../../public/images/forger-share.png');

        self::assertNotFalse($size);
        self::assertSame([1200, 630], [$size[0], $size[1]]);
    }
}
