<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\SprintBoardRepository;
use App\Validator\Constraints as CustomAssert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SprintBoardRepository::class)]
#[UniqueEntity('identifier')]
class SprintBoard implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private readonly int $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 200)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: SprintBoardCategory::class, inversedBy: 'sprintBoards')]
    #[ORM\JoinColumn(nullable: false)]
    private ?SprintBoardCategory $category = null;

    #[ORM\Column(type: 'text')]
    #[CustomAssert\ConstrainsYaml]
    #[Assert\Length(min: 1, max: 2000)]
    private ?string $query = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 200)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', htmlPattern: '^[a-z0-9]+(?:-[a-z0-9]+)*$')]
    private ?string $identifier = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sorting = null;

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

    public function getCategory(): ?SprintBoardCategory
    {
        return $this->category;
    }

    public function setCategory(?SprintBoardCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function setQuery(string $query): self
    {
        $this->query = $query;

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

    public function getSorting(): ?int
    {
        return $this->sorting;
    }

    public function setSorting(?int $sorting): self
    {
        $this->sorting = $sorting;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
