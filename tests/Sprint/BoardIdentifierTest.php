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
use App\Repository\SprintBoardRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The address of a sprint board, made from its name, see SprintBoardRepository::identifierFor().
 */
final class BoardIdentifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?int, string}>
     */
    public static function names(): iterable
    {
        yield 'words and digits' => ['Contribution Night 2026', null, 'contribution-night-2026'];
        yield 'signs and umlauts' => ['FormEngine & Größe!', null, 'formengine-grosse'];
        yield 'only signs' => ['???', null, 'board'];
        yield 'taken by another board' => ['Site Handling', null, 'site-handling-3'];
        yield 'its own identifier' => ['Site Handling', 12, 'site-handling'];
    }

    #[DataProvider('names')]
    public function testTheIdentifierComesFromTheName(string $name, ?int $id, string $expected): void
    {
        // "site-handling" is the board 12, "site-handling-2" the board 13
        $taken = ['site-handling' => 12, 'site-handling-2' => 13];
        $repository = $this->createPartialMock(SprintBoardRepository::class, ['findOneBy']);
        $boardOf = static function (array $criteria) use ($taken): ?SprintBoard {
            $number = $taken[$criteria['identifier']] ?? null;
            if (null === $number) {
                return null;
            }
            $board = new SprintBoard();
            (new \ReflectionProperty(SprintBoard::class, 'id'))->setValue($board, $number);

            return $board;
        };
        $repository->expects(self::atLeastOnce())->method('findOneBy')->willReturnCallback($boardOf);

        self::assertSame($expected, $repository->identifierFor($name, $id));
    }

    public function testEveryBoardHasItsOwnUuid(): void
    {
        $uuid = (new SprintBoard())->getUuid();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
        self::assertNotSame($uuid, (new SprintBoard())->getUuid());
    }
}
