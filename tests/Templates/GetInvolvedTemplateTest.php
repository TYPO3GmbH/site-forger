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
use App\People\Person;
use App\Report\MergePerson;
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
            'people' => [
                new MergePerson(new Person('Benni Mack', email: 'benni@example.org'), $change, true),
                new MergePerson(new Person('Georg Ringer'), $change, false),
            ],
            'smallReviews' => SearchResult::ofDocuments([$change], 61),
            'goodFirstIssues' => SearchResult::ofDocuments([$issue], 23),
            'newBugs' => SearchResult::ofDocuments([], 0),
        ]);

        // Each task shows its first items and leads to all of them
        self::assertStringContainsString('Show all 61', $html);
        self::assertStringContainsString('href="/change/81234"', $html);
        self::assertStringContainsString('href="/issue/91089"', $html);
        self::assertStringContainsString('Show the list', $html);
        // Each item shows its state below its title
        self::assertStringContainsString('<span class="task-card-subject">Tidy up</span>', $html);
        self::assertStringContainsString('status-pill-new', $html);
        self::assertStringContainsString('status-pill-mark-good-first-issue', $html);
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
        self::assertSame(4, substr_count($html, 'class="info-card get-involved-step"'));
        // The people of the last merges, by their face, the address for the avatar only
        // Each face leads to the change of the person and says what the person did
        self::assertStringContainsString('<li class="hero-face hero-face-1">', $html);
        self::assertStringContainsString('<li class="hero-face hero-face-2">', $html);
        self::assertStringContainsString('href="/change/81234" title="Benni Mack wrote: Tidy up"', $html);
        self::assertStringContainsString('title="Georg Ringer reviewed: Tidy up"', $html);
        self::assertStringContainsString('The people behind the last merges', $html);
        self::assertStringNotContainsString('benni@example.org', $html);
    }

    public function testWithoutMergesThereAreNoFaces(): void
    {
        $html = $this->twig->load('get_involved/index.html.twig')->renderBlock('body', [
            'people' => [],
            'smallReviews' => SearchResult::ofDocuments([], 0),
            'goodFirstIssues' => SearchResult::ofDocuments([], 0),
            'newBugs' => SearchResult::ofDocuments([], 0),
        ]);

        self::assertStringNotContainsString('hero-people', $html);
    }
}
