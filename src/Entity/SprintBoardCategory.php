<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\SprintBoardCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SprintBoardCategoryRepository::class)]
#[UniqueEntity('identifier')]
class SprintBoardCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private readonly int $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 200)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 200)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', htmlPattern: '^[a-z0-9]+(?:-[a-z0-9]+)*$')]
    private ?string $identifier = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 200)]
    private ?string $icon = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sorting = null;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: SprintBoard::class, orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private readonly Collection $sprintBoards;

    public function __construct()
    {
        $this->sprintBoards = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function getSorting(): ?int
    {
        return $this->sorting;
    }

    public function setSorting(?int $sorting): self
    {
        $this->sorting = $sorting;

        return $this;
    }

    /**
     * @return Collection<int, SprintBoard>
     */
    public function getSprintBoards(): Collection
    {
        return $this->sprintBoards;
    }
}
