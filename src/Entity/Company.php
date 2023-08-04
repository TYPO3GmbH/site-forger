<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\CompanyRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 200)]
    private ?string $name = null;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: EmploymentTime::class, cascade: ['remove'])]
    private Collection $employmentTimes;

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

    public function getEmploymentTimes(): Collection
    {
        return $this->employmentTimes;
    }

    public function setEmploymentTimes(array $employmentTimes): self
    {
        $this->employmentTimes = $employmentTimes;

        return $this;
    }
}
