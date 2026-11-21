<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Document\Change;
use App\Document\Issue;
use App\Elastic\SearchResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The page "Get involved", rendered with strict variables.
 */
final class GetInvolvedTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
        self::assertTrue($this->twig->isStrictVariables());
    }

    public function testEachListLeadsToAllOfItsItems(): void
    {
        $issue = Issue::fromDocument([
            'id' => 91089,
            'subject' => 'Something is broken',
            'status' => ['id' => 1, 'name' => 'New'],
            'complexity' => 'easy',
            'created_on' => '2026/11/01 10:00:00',
        ]);
        $change = Change::fromDocument([
            'id' => 81234,
            'subject' => '[TASK] Tidy up',
            'updated_on' => '2026/11/01 10:00:00',
        ]);
        $html = $this->twig->load('get_involved/index.html.twig')->renderBlock('body', [
            'smallReviews' => SearchResult::ofDocuments([$change], 61),
            'goodFirstIssues' => SearchResult::ofDocuments([$issue], 23),
            'newBugs' => SearchResult::ofDocuments([], 0),
        ]);

        self::assertStringContainsString('<span class="overview-list-count">61</span>', $html);
        self::assertStringContainsString('href="/change/81234"', $html);
        self::assertStringContainsString('href="/reviews/ready?filters%5Bsize%5D%5BEasy%5D=true"', $html);
        self::assertStringContainsString(
            'href="/search?filters%5Bmarked_as%5D%5BGood_first_issue%5D=true&amp;sort=updated"',
            $html,
        );
        self::assertStringContainsString(
            'href="/search?filters%5Btracker%5D%5BBug%5D=true&amp;filters%5Bstatus%5D%5BNew%5D=true&amp;sort=newest"',
            $html,
        );
        self::assertStringContainsString('There is no new bug.', $html);
        // Regressions are rarely a first contribution, the dashboard lists them
        self::assertStringNotContainsString('regression', strtolower($html));
        self::assertStringContainsString(
            'href="https://docs.typo3.org/m/typo3/guide-contributionworkflow/main/en-us/Account/Index.html"',
            $html,
        );
        // The step to a task leads to the lists on the same page, without a new tab
        self::assertMatchesRegularExpression('~href="#get-involved-tasks"\\s*>~', $html);
        self::assertStringContainsString('id="get-involved-tasks"', $html);
        self::assertSame(4, substr_count($html, 'class="get-involved-step"'));
    }
}
