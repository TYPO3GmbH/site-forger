<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Sprint;

use App\Entity\SprintBoard;
use App\Entity\SprintBoardCategory;
use App\Sprint\BoardCount;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * A board on the sprint overview, rendered with strict variables.
 */
final class OverviewBoardTemplateTest extends KernelTestCase
{
    private Environment $twig;

    private SprintBoard $board;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');

        $category = (new SprintBoardCategory())->setName('Core')->setIdentifier('core')->setIcon('actions-tag');
        $this->board = (new SprintBoard())->setName('Sprint 42')->setIdentifier('sprint-42')->setQuery('match_all: {}')->setCategory($category);
        $id = new \ReflectionProperty(SprintBoard::class, 'id');
        $id->setValue($this->board, 7);
    }

    public function testBoardWithIssues(): void
    {
        $html = $this->render(new BoardCount(open: 3, closed: 5, blocked: 0, review: 2));

        self::assertStringContainsString('Sprint 42', $html);
        self::assertStringContainsString('/sprint/core/7', $html);
        self::assertStringContainsString('50%', $html);
        self::assertStringContainsString('5 of 10 issues done', $html);
        self::assertMatchesRegularExpression('/sprint-board-state-review"\s+style="flex-grow: 2"/', $html);
        self::assertDoesNotMatchRegularExpression('/sprint-board-state-blocked"\s+style/', $html);
        // The states in the order of the columns of the board, an empty one dimmed
        self::assertMatchesRegularExpression(
            '/is-empty">\s*<strong>\s*<span[^>]*blocked[^>]*><\/span>0<\/strong>\s*Blocked/',
            $html,
        );
        self::assertMatchesRegularExpression('/Blocked.*Open.*Review.*Done/s', (string) strstr($html, 'card-legend'));
    }

    public function testBoardWithoutIssues(): void
    {
        $html = $this->render(new BoardCount(0, 0, 0, 0));

        self::assertStringContainsString('No issues match this board.', $html);
        self::assertStringNotContainsString('overview-segments', $html);
    }

    public function testBoardWithUnreadableQuery(): void
    {
        self::assertStringContainsString('cannot be read', $this->render(null));
    }

    private function render(?BoardCount $count): string
    {
        return $this->twig->render('sprint/_overview_board.html.twig', ['board' => $this->board, 'count' => $count]);
    }
}
