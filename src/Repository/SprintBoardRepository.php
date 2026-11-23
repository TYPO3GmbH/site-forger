<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\SprintBoard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * @extends ServiceEntityRepository<SprintBoard>
 */
class SprintBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SprintBoard::class);
    }

    #[\Override]
    public function findAll(): array
    {
        return $this->findBy([], ['sorting' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * The identifier of a board with the name: the name in lower case letters, digits and "-", like
     * "contribution-night-2026". With "-2", "-3" and so on while another board has it. The board of the id may
     * keep its own.
     */
    public function identifierFor(string $name, ?int $id = null): string
    {
        $base = (new AsciiSlugger())->slug($name)->lower()->toString();
        $base = '' === $base ? 'board' : $base;
        $identifier = $base;
        for ($number = 2; $this->isTaken($identifier, $id); ++$number) {
            $identifier = $base . '-' . $number;
        }

        return $identifier;
    }

    private function isTaken(string $identifier, ?int $id): bool
    {
        $board = $this->findOneBy(['identifier' => $identifier]);

        return null !== $board && $board->getId() !== $id;
    }
}
