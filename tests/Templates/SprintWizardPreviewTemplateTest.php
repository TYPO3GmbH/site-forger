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
        ]);

        $html = $this->twig->render('sprint/_wizard_preview.html.twig', [
            'hasFilter' => true,
            'total' => 1,
            'columns' => [new BoardColumn(BoardColumnEnum::REVIEW, 1)],
            'issues' => new ArrayCollection([new Card($issue, BoardColumnEnum::REVIEW)]),
        ]);

        self::assertStringContainsString('#91089 Something is broken</a>', $html);
        self::assertStringContainsString('<td>Bug</td>', $html);
        self::assertStringContainsString('<td>Under Review</td>', $html);
        self::assertStringContainsString('<td>Review</td>', $html);
    }
}
