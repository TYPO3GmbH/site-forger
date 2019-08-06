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
 * @ORM\Entity(repositoryClass="App\Repository\BackportBlacklistEntryRepository")
 */
class BackportBlacklistEntry
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $changeId;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $Comment;

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
