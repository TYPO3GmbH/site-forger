<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\ActivityEnum;
use App\Enum\IssueStatusEnum;
use App\People\Person;

/**
 * A Forge issue as the issue index has it, see ForgeIssueImporter.
 *
 * The status, tracker, project, priority, category and version are references as Forge sends them: an id and a
 * name. The author and the assignee are a Person.
 * Documents of older imports lack some fields. These fields are null or empty then.
 */
final readonly class Issue
{
    /**
     * @param array{id?: int, name?: string}|null $status
     * @param array{id?: int, name?: string}|null $tracker
     * @param array{id?: int, name?: string}|null $project
     * @param array{id?: int, name?: string}|null $priority
     * @param array{id?: int, name?: string}|null $category
     * @param array{id?: int, name?: string}|null $fixedVersion
     * @param list<string>                        $tags
     * @param list<array<string, mixed>>          $customFields
     * @param list<array<string, mixed>>          $journals     the comments and changes, as Forge lists them
     * @param list<int>                           $duplicates   the issues this one duplicates, a relation of Forge
     * @param list<int>                           $duplicatedBy the issues that duplicate this one
     * @param int|null                            $parent       the issue this one is a subtask of
     * @param list<IssueRelation>                 $relations    the other relations of Forge
     * @param list<IssueAttachment>               $attachments  the files, the oldest first
     */
    public function __construct(
        public int $id,
        public string $subject,
        public string $description = '',
        public ?array $status = null,
        public ?array $tracker = null,
        public ?array $project = null,
        public ?array $priority = null,
        public ?array $category = null,
        public ?array $fixedVersion = null,
        public ?Person $author = null,
        public ?Person $assignedTo = null,
        public ?string $createdOn = null,
        public ?string $updatedOn = null,
        public array $tags = [],
        public ?string $phpVersion = null,
        public ?string $typo3Version = null,
        public ?string $complexity = null,
        public array $customFields = [],
        public array $journals = [],
        public array $duplicates = [],
        public array $duplicatedBy = [],
        public ?int $parent = null,
        public array $relations = [],
        public array $attachments = [],
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): self
    {
        return new self(
            id: (int) ($document['id'] ?? 0),
            subject: (string) ($document['subject'] ?? ''),
            description: (string) ($document['description'] ?? ''),
            status: self::arrayOrNull($document['status'] ?? null),
            tracker: self::arrayOrNull($document['tracker'] ?? null),
            project: self::arrayOrNull($document['project'] ?? null),
            priority: self::arrayOrNull($document['priority'] ?? null),
            category: self::arrayOrNull($document['category'] ?? null),
            fixedVersion: self::arrayOrNull($document['fixed_version'] ?? null),
            author: Person::fromIssue($document['author'] ?? null),
            assignedTo: Person::fromIssue($document['assigned_to'] ?? null),
            createdOn: self::stringOrNull($document['created_on'] ?? null),
            updatedOn: self::stringOrNull($document['updated_on'] ?? null),
            tags: array_values(array_map('strval', (array) ($document['tags'] ?? []))),
            phpVersion: self::stringOrNull($document['php_version'] ?? null),
            typo3Version: self::stringOrNull($document['typo3_version'] ?? null),
            complexity: self::stringOrNull($document['complexity'] ?? null),
            customFields: array_values((array) ($document['custom_fields'] ?? [])),
            journals: array_values((array) ($document['journals'] ?? [])),
            duplicates: self::numbers($document['duplicates'] ?? []),
            duplicatedBy: self::numbers($document['duplicated_by'] ?? []),
            parent: isset($document['parent']['id']) ? (int) $document['parent']['id'] : null,
            relations: IssueRelation::listFrom($document['issue_relations'] ?? []),
            attachments: IssueAttachment::listFrom($document['attachments'] ?? []),
        );
    }

    /**
     * The status as IssueStatusEnum, null for a status Forger does not know.
     */
    public function statusEnum(): ?IssueStatusEnum
    {
        return isset($this->status['id']) ? IssueStatusEnum::tryFrom((int) $this->status['id']) : null;
    }

    /**
     * How long nothing happened to the open issue, null for a closed one.
     */
    public function activity(\DateTimeInterface $now): ?ActivityEnum
    {
        if (true === $this->statusEnum()?->isClosed()) {
            return null;
        }
        $dates = ['updated_on' => $this->updatedOn, 'created_on' => $this->createdOn];
        $lastActivity = IndexDates::lastActivity($dates);

        return null === $lastActivity ? null : ActivityEnum::fromDate($lastActivity, $now);
    }

    /**
     * The history: the comments and the changes of the fields, the oldest first.
     *
     * @return list<IssueJournal>
     */
    public function history(): array
    {
        return array_values(array_map(
            IssueJournal::fromJournal(...),
            array_filter(
                $this->journals,
                static fn (array $journal): bool => true !== ($journal['private_notes'] ?? false),
            ),
        ));
    }

    /**
     * The Gerrit changes the journals link, see GerritLinks.
     *
     * @return list<int>
     */
    public function changeNumbers(): array
    {
        return GerritLinks::inJournals($this->journals);
    }

    /**
     * The issues Forge relates to this one as duplicates, in either direction.
     *
     * @return list<int>
     */
    public function duplicateNumbers(): array
    {
        return array_values(array_unique([...$this->duplicates, ...$this->duplicatedBy]));
    }

    /**
     * @return list<int>
     */
    private static function numbers(mixed $value): array
    {
        return array_values(array_map(intval(...), (array) $value));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
