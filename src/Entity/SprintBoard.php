<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use App\Enum\BoardTypeEnum;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewSortEnum;
use App\Repository\SprintBoardRepository;
use App\Sprint\ReviewBoardFilter;
use App\Validator\Constraints as CustomAssert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Yaml\Yaml;

#[ORM\Entity(repositoryClass: SprintBoardRepository::class)]
#[UniqueEntity('identifier')]
class SprintBoard implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * The board in the addresses that change it, like /boards/edit/<uuid>: its number never shows, and its
     * identifier changes with its name.
     */
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 1, max: 200)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: SprintBoardCategory::class, inversedBy: 'sprintBoards')]
    #[ORM\JoinColumn(nullable: false)]
    private ?SprintBoardCategory $category = null;

    /**
     * The query of the board as YAML: for the issue index, or for the review index on a board of reviews.
     */
    #[ORM\Column(type: 'text')]
    #[CustomAssert\ConstrainsYaml]
    #[Assert\Length(min: 1, max: 2000)]
    private ?string $query = null;

    /**
     * The name of the board in its address, like "contribution-night" of /boards/core/contribution-night: unique,
     * and made from the name whenever it changes, see SprintBoardRepository::identifierFor(). Nobody edits it.
     */
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    #[Assert\Length(min: 1, max: 200)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', htmlPattern: '^[a-z0-9]+(?:-[a-z0-9]+)*$')]
    private ?string $identifier = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sorting = null;

    /**
     * The order of the issues in each column, see IssueSortQuery. The viewer may choose another one.
     */
    #[ORM\Column(type: 'string', length: 30, enumType: IssueSortEnum::class, options: ['default' => 'updated'])]
    private IssueSortEnum $cardSort = IssueSortEnum::UPDATED;

    /**
     * The order of the changes in each column of a board of reviews, see ReviewSortQuery. The viewer may choose
     * another one.
     */
    #[ORM\Column(type: 'string', length: 30, enumType: ReviewSortEnum::class, options: ['default' => 'least-updated'])]
    private ReviewSortEnum $reviewSort = ReviewSortEnum::LEAST_UPDATED;

    /**
     * Whether the board shows issues or reviews. It never changes: the filters of one type mean nothing to the other.
     */
    #[ORM\Column(type: 'string', length: 10, enumType: BoardTypeEnum::class, options: ['default' => 'issues'])]
    private BoardTypeEnum $type = BoardTypeEnum::ISSUES;

    /**
     * The view a board of issues opens with: its issues, or the reviews of its issues. The other one is a click
     * away. A board of reviews has one view only.
     */
    #[ORM\Column(type: 'string', length: 10, enumType: BoardTypeEnum::class, options: ['default' => 'issues'])]
    private BoardTypeEnum $firstView = BoardTypeEnum::ISSUES;

    /**
     * The filters of a board of reviews, as ReviewBoardFilter::toArray() writes them. Its query follows them.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $reviewFilter = null;

    /**
     * Whether only its members, the managers and the admins see the board, see SprintBoardVoter.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $restricted = false;

    /**
     * @var Collection<int, SprintBoardMember>
     */
    #[ORM\OneToMany(
        mappedBy: 'board',
        targetEntity: SprintBoardMember::class,
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['username' => \SortDirection::Ascending])]
    private Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->uuid = self::newUuid();
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * A random UUID (version 4).
     */
    private static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
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

    public function getCardSort(): IssueSortEnum
    {
        return $this->cardSort;
    }

    public function setCardSort(IssueSortEnum $cardSort): self
    {
        $this->cardSort = $cardSort;

        return $this;
    }

    public function getReviewSort(): ReviewSortEnum
    {
        return $this->reviewSort;
    }

    public function setReviewSort(ReviewSortEnum $reviewSort): self
    {
        $this->reviewSort = $reviewSort;

        return $this;
    }

    public function getType(): BoardTypeEnum
    {
        return $this->type;
    }

    public function setType(BoardTypeEnum $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getFirstView(): BoardTypeEnum
    {
        return $this->firstView;
    }

    public function setFirstView(BoardTypeEnum $firstView): self
    {
        $this->firstView = $firstView;

        return $this;
    }

    public function getReviewFilter(): ReviewBoardFilter
    {
        return ReviewBoardFilter::fromArray($this->reviewFilter);
    }

    /**
     * Sets the filters of a board of reviews, and its query with them.
     */
    public function setReviewFilter(ReviewBoardFilter $filter): self
    {
        $this->reviewFilter = $filter->toArray();
        $this->query = Yaml::dump($filter->toQuery(), 10, 2);

        return $this;
    }

    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    public function setRestricted(bool $restricted): self
    {
        $this->restricted = $restricted;

        return $this;
    }

    /**
     * @return Collection<int, SprintBoardMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * Whether the board is shared with the TYPO3 account of the username.
     */
    public function hasMember(string $username): bool
    {
        $username = mb_strtolower($username);

        return $this->members->exists(
            static fn (int $key, SprintBoardMember $member): bool => $member->getUsername() === $username,
        );
    }

    /**
     * Shares the board with a TYPO3 account, once.
     */
    public function addMember(string $username, \DateTimeImmutable $now): void
    {
        if (!$this->hasMember($username)) {
            $this->members->add(new SprintBoardMember($this, $username, $now));
        }
    }

    /**
     * Shares the board with exactly these TYPO3 accounts: adds the new ones, removes the others.
     *
     * @param list<string> $usernames
     */
    public function setMembers(array $usernames, \DateTimeImmutable $now): void
    {
        $usernames = array_map(mb_strtolower(...), $usernames);
        foreach ($this->members->toArray() as $member) {
            if (!in_array($member->getUsername(), $usernames, true)) {
                $this->members->removeElement($member);
            }
        }
        foreach ($usernames as $username) {
            $this->addMember($username, $now);
        }
    }

    public function removeMember(string $username): void
    {
        $username = mb_strtolower($username);
        foreach ($this->members as $member) {
            if ($member->getUsername() === $username) {
                $this->members->removeElement($member);
            }
        }
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
