<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\EmploymentTimeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EmploymentTimeRepository::class)]
class EmploymentTime
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'employmentTimes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(type: 'datetime')]
    #[Assert\LessThan('tomorrow')]
    private ?\DateTimeInterface $employedFrom = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Assert\LessThan('tomorrow')]
    private ?\DateTimeInterface $employedTo = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $account = null;

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
