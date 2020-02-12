<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Validator\Constraints as CustomAssert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="App\Repository\SprintBoardRepository")
 * @UniqueEntity("identifier")
 */
class SprintBoard
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\Length(min = 1, max = 200)
     */
    private $name;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\SprintBoardCategory")
     * @ORM\JoinColumn(nullable=false)
     */
    private $category;

    /**
     * @ORM\Column(type="text")
     * @Assert\Length(min = 1, max = 2000)
     * @CustomAssert\ConstrainsYaml
     */
    private $query;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\Length(min = 1, max = 200)
     * @Assert\Regex(pattern="/^[a-z0-9]+(?:-[a-z0-9]+)*$/",htmlPattern="^[a-z0-9]+(?:-[a-z0-9]+)*$")
     */
    private $identifier;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $sorting;

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
}
