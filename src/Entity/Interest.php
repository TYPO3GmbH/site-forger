<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\InterestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An area a user cares for, like the documentation: the files of the changes and the categories of the issues.
 * The overview of My Work lists the changes to review and the new issues of it, see OverviewController.
 */
#[ORM\Entity(repositoryClass: InterestRepository::class)]
#[ORM\Table(name: 'interest')]
#[ORM\Index(name: 'interest_owner', columns: ['owner'])]
class Interest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * @param list<string> $paths      paths and patterns of files, as the filter "File" of the review lists takes
     *                                 them, see App\Search\ReviewLists::fileQuery()
     * @param list<string> $categories names of categories on Forge
     */
    public function __construct(
        /** The username of the TYPO3 account */
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $owner,
        #[ORM\Column(type: Types::STRING, length: 100)]
        private string $name,
        #[ORM\Column(type: Types::JSON)]
        private array $paths,
        #[ORM\Column(type: Types::JSON)]
        private array $categories,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function getPaths(): array
    {
        return array_map(strval(...), $this->paths);
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        return array_map(strval(...), $this->categories);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $categories
     */
    public function change(string $name, array $paths, array $categories): void
    {
        $this->name = $name;
        $this->paths = $paths;
        $this->categories = $categories;
    }
}
