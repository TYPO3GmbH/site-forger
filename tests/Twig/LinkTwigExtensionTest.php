<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Twig\Environment;

final class LinkTwigExtensionTest extends KernelTestCase
{
    public function testLinks(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        self::assertSame(
            '/issue/91089 /change/81234 https://forge.typo3.org/issues/91089 https://review.typo3.org/c/Packages/TYPO3.CMS/+/81234/',
            $twig->createTemplate("{{ issue_path(91089) }} {{ change_path('81234') }} {{ forge_issue_url(91089) }} {{ gerrit_change_url(81234) }}")->render()
        );
    }

    public function testTheLinksToWhereTheWorkIsDone(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $render = static fn (string $template): string => $twig->createTemplate($template)->render();
        $change = 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/81234/';

        self::assertSame('https://forge.typo3.org/issues/91089/edit', $render('{{ forge_issue_edit_url(91089) }}'));
        self::assertSame(
            'https://forge.typo3.org/issues/91089#change-584554',
            $render('{{ forge_journal_url(91089, 584554) }}'),
        );
        self::assertSame($change . '3', $render('{{ gerrit_patch_set_url(81234, 3) }}'));
        self::assertSame($change . '3//COMMIT_MSG', $render("{{ gerrit_patch_set_url(81234, 3, '/COMMIT_MSG') }}"));
        self::assertSame(
            $change . '3/typo3/sysext/core/Tests/Fixture%20Files/a%23b.php',
            $render("{{ gerrit_patch_set_url(81234, 3, 'typo3/sysext/core/Tests/Fixture Files/a#b.php') }}"),
        );
    }

    public function testThePagesOfTheContributionGuide(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $guide = 'https://docs.typo3.org/m/typo3/guide-contributionworkflow/main/en-us/';

        self::assertSame(
            $guide . ' ' . $guide . 'Account/Index.html',
            $twig->createTemplate("{{ contribution_guide_url() }} {{ contribution_guide_url('/Account/Index.html') }}")
                ->render(),
        );
    }

    public function testTheIssuesOfTheFootersAreLinked(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $html = $twig->createTemplate('{{ message|link_footer_issues(imported) }}')->render([
            'message' => "Fixes <script> for #99999 in the text\n\nResolves: #105123, #105124\nRelated: 104000\nReleases: main, 13.4",
            // #105124 is not imported: Forger has no page for it
            'imported' => [105123, '104000', 99999],
        ]);

        self::assertSame(
            "Fixes &lt;script&gt; for #99999 in the text\n\nResolves: <a href=\"/issue/105123\">#105123</a>, #105124\nRelated: <a href=\"/issue/104000\">104000</a>\nReleases: main, 13.4",
            $html
        );
        self::assertStringNotContainsString('<a', $twig->createTemplate('{{ message|link_footer_issues }}')->render(['message' => "Subject\n\nResolves: #105123"]));
    }

    /**
     * An issue or a change is linked through the helpers only, so all lists lead to the same place.
     */
    public function testLinksTheAddressesOfAText(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $template = $twig->createTemplate('{{ text|autolink }}');
        $render = static fn (string $text): string => $template->render(['text' => $text]);

        self::assertSame(
            'Core CI is not happy: <a href="https://git.typo3.org/p/1" target="_blank" rel="noopener">'
                . 'https://git.typo3.org/p/1</a>.',
            $render('Core CI is not happy: https://git.typo3.org/p/1.'),
        );
        self::assertSame('&lt;b&gt;no link&lt;/b&gt;', $render('<b>no link</b>'));
    }

    public function testTemplatesUseTheHelpers(): void
    {
        $finder = new Finder()->files()->in(__DIR__ . '/../../templates')->name('*.twig');
        foreach ($finder as $file) {
            $contents = $file->getContents();
            self::assertDoesNotMatchRegularExpression('~forge\.typo3\.org/issues/|review\.typo3\.org/c/~', $contents, $file->getRelativePathname());
            self::assertDoesNotMatchRegularExpression("~path\\('(issue_show|change_show)'~", $contents, $file->getRelativePathname());
        }
    }
}
