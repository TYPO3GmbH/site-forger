<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="App\Repository\SprintBoardCategoryRepository")
 * @UniqueEntity("identifier")
 */
class SprintBoardCategory
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
     * @ORM\Column(type="string", length=255)
     * @Assert\Length(min = 1, max = 200)
     * @Assert\Regex(pattern="/^[a-z0-9]+(?:-[a-z0-9]+)*$/",htmlPattern="^[a-z0-9]+(?:-[a-z0-9]+)*$")
     */
    private $identifier;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\Length(min = 1, max = 200)
     */
    private $icon;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $sorting;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\SprintBoard", mappedBy="category", orphanRemoval=true)
     * @ORM\OrderBy({"name" = "ASC"})
     */
    private $sprintBoards;

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
     * @return ArrayCollection|SprintBoard[]
     */
    public function getSprintBoards()
    {
        return $this->sprintBoards;
    }
}
