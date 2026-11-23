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
use App\Sprint\SprintBoards;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The parts of a sprint board that the board loads again every minute, see SprintController::columns().
 */
final class SprintBoardTemplateTest extends KernelTestCase
{
    use KnowsNoPeople;

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
        self::assertTrue($this->twig->isStrictVariables());
    }

    public function testEachPartCanTakeThePlaceOfTheOldOne(): void
    {
        $html = $this->render(30, 10);

        foreach (['summary', 'progress', 'columns'] as $part) {
            self::assertSame(1, substr_count($html, 'data-board-part="' . $part . '"'));
        }
        self::assertStringContainsString('<strong>25%</strong> done', $html);
        self::assertStringContainsString('10 of 40 issues', $html);
        self::assertStringContainsString('#91089', $html);
    }

    public function testALongColumnShowsItsFirstCardsAndTheRestOnAClick(): void
    {
        $html = $this->render(30, 0);

        self::assertSame(1, substr_count($html, 'class="sprint-board-cards show-more-rows"'));
        self::assertStringContainsString('Show all 30', $html);
        self::assertStringContainsString('Nothing here.', $html);
    }

    private function render(int $open, int $done): string
    {
        $issue = Issue::fromDocument([
            'id' => 91089,
            'subject' => 'Something is broken',
            'tracker' => ['id' => 1, 'name' => 'Bug'],
            'status' => ['id' => 1, 'name' => 'New'],
        ]);
        $cards = new ArrayCollection(array_fill(0, $open, new Card($issue, BoardColumnEnum::OPEN)));
        $columns = [
            new BoardColumn(BoardColumnEnum::BLOCKED, 0),
            new BoardColumn(BoardColumnEnum::OPEN, $open, $cards),
            new BoardColumn(BoardColumnEnum::DONE, $done),
        ];

        return $this->twig->render('sprint/_board_parts.html.twig', [
            'cards' => $columns,
            'progress' => SprintBoards::progress($columns),
        ]);
    }
}
