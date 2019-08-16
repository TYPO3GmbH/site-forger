<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\EmploymentTimeRepository")
 */
class EmploymentTime
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Company")
     * @ORM\JoinColumn(nullable=false)
     */
    private $company;

    /**
     * @ORM\Column(type="datetime")
     */
    private $employedFrom;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $employedTo;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $account;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getEmployedFrom(): ?\DateTimeInterface
    {
        return $this->employedFrom;
    }

    public function setEmployedFrom(\DateTimeInterface $employedFrom): self
    {
        $this->employedFrom = $employedFrom;

        return $this;
    }

    public function getEmployedTo(): ?\DateTimeInterface
    {
        return $this->employedTo;
    }

    public function setEmployedTo(?\DateTimeInterface $employedTo): self
    {
        $this->employedTo = $employedTo;

        return $this;
    }

    public function getAccount(): ?string
    {
        return $this->account;
    }

    public function setAccount(string $account): self
    {
        $this->account = $account;

        return $this;
    }
}
