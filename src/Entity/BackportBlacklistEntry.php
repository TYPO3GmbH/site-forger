<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\BackportBlacklistEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BackportBlacklistEntryRepository::class)]
class BackportBlacklistEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private readonly int $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 200)]
    private ?string $changeId = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 250)]
    private ?string $Comment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChangeId(): ?string
    {
        return $this->changeId;
    }

    public function setChangeId(string $changeId): self
    {
        $this->changeId = $changeId;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->Comment;
    }

    public function setComment(string $Comment): self
    {
        $this->Comment = $Comment;

        return $this;
    }
}
