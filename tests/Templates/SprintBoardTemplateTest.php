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
use App\Sprint\BoardColumn;
use App\Sprint\BoardColumnEnum;
use App\Sprint\Card;
use App\Sprint\SprintBoards;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The parts of a sprint board and of the review board that load again every minute, see
 * SprintController::columns() and SprintController::reviewColumns().
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

    public function testABoardOfReviewsHasTheSamePartsWithItsOwnColumns(): void
    {
        $change = Change::fromDocument([
            'id' => 81234,
            'subject' => '[BUGFIX] Clear the cache',
            'reviewtags' => ['BUGFIX'],
            'reviewstatus' => 'NEW',
            'updated_on' => '2026/11/02 10:00:00',
        ]);
        $html = $this->twig->render('sprint/_review_parts.html.twig', [
            'cards' => [
                'missesBoth' => new ArrayCollection([$change, $change]),
                'blocked' => new ArrayCollection(),
            ],
        ]);

        self::assertSame(1, substr_count($html, 'data-board-part="summary"'));
        self::assertSame(1, substr_count($html, 'data-board-part="columns"'));
        self::assertMatchesRegularExpression('/<strong>2<\/strong>\s+open changes, by the votes they still need/', $html);
        self::assertStringContainsString('Unchecked', $html);
        // The subject without its tag, which stands as a label above it
        self::assertStringContainsString('>Clear the cache</a>', $html);
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
