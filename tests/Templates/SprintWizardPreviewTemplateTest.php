<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Document\Issue;
use App\Sprint\BoardColumn;
use App\Sprint\BoardColumnEnum;
use App\Sprint\Card;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class SprintWizardPreviewTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    public function testEachIssueShowsItsColumn(): void
    {
        $issue = Issue::fromDocument([
            'id' => 91089,
            'subject' => 'Something is broken',
            'tracker' => ['id' => 1, 'name' => 'Bug'],
            'status' => ['id' => 9, 'name' => 'Under Review'],
            'created_on' => '2026/11/01 10:00:00',
        ]);

        $html = $this->twig->render('sprint/_wizard_preview.html.twig', [
            'hasFilter' => true,
            'total' => 1,
            'columns' => [new BoardColumn(BoardColumnEnum::REVIEW, 1)],
            'issues' => new ArrayCollection([new Card($issue, BoardColumnEnum::REVIEW)]),
        ]);

        self::assertStringContainsString('href="/issue/91089"', $html);
        self::assertStringContainsString('Something is broken', $html);
        self::assertStringContainsString('<strong>1</strong> issue</p>', $html);
        // The columns in the order and the colors of the board, the empty ones too
        self::assertMatchesRegularExpression('/sprint-board-state-review"\s+style="flex-grow: 1"/', $html);
        self::assertMatchesRegularExpression('/In review\s*<strong>1<\/strong>/', $html);
        self::assertStringContainsString('Bug · Under Review · In review', $html);
    }
}
