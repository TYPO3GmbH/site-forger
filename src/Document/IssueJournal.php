<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\IssueFieldEnum;
use App\Enum\IssueRelationEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\PriorityEnum;
use App\Enum\TrackerEnum;
use App\People\Person;

/**
 * An entry of the history of a Forge issue, as Forge keeps it in its journals: a comment, the fields it changes, or
 * both. The import leaves out the private comments.
 *
 * A bot of Forge writes notes, too: Gerrit one for each patch set, with the change it belongs to.
 */
final readonly class IssueJournal
{
    /**
     * The accounts on Forge that write notes for a tool, not as a person.
     */
    public const array BOTS = ['Gerrit Code Review', 'Mr. Hudson'];

    /**
     * The custom fields of the project, by their id on Forge, see ForgeIssueImporter::document().
     */
    private const array CUSTOM_FIELDS = [
        3 => 'Tags',
        4 => 'TYPO3 Version',
        5 => 'PHP Version',
        8 => 'Complexity',
        15 => 'Is Regression',
        18 => 'Sprint Focus',
    ];

    /**
     * The relations of duplicates, which IssueRelationEnum leaves to the duplicates page.
     */
    private const array DUPLICATES = ['duplicates' => 'Duplicates', 'duplicated' => 'Duplicated by'];

    /**
     * The note Gerrit writes for a patch set.
     */
    private const string PATCH_SET = '/^Patch set (?<patchSet>\d+) for branch \*?(?<branch>[^*\s]+)\*? of project/';

    /**
     * @param list<IssueFieldChange> $changes the fields it changes
     * @param int|null               $change  the Gerrit change of a note of Gerrit on a patch set
     * @param int|null               $id      the id of Forge, for the link to the entry; older imports have none
     */
    public function __construct(
        public ?Person $author,
        public ?string $createdOn,
        public string $notes,
        public array $changes = [],
        public ?int $change = null,
        public ?int $patchSet = null,
        public ?string $branch = null,
        public ?int $id = null,
    ) {
    }

    /**
     * @param array<string, mixed> $journal a journal as Forge sends it
     */
    public static function fromJournal(array $journal): self
    {
        $notes = trim((string) ($journal['notes'] ?? ''));
        $patchSet = 1 === preg_match(self::PATCH_SET, $notes, $match) ? $match : null;
        $changes = GerritLinks::inJournals([['notes' => $notes]]);

        return new self(
            author: Person::fromIssue($journal['user'] ?? null),
            createdOn: isset($journal['created_on']) ? (string) $journal['created_on'] : null,
            notes: $notes,
            changes: array_values(array_filter(array_map(
                self::change(...),
                array_filter((array) ($journal['details'] ?? []), is_array(...)),
            ))),
            change: null === $patchSet ? null : ($changes[0] ?? null),
            patchSet: null === $patchSet ? null : (int) $patchSet['patchSet'],
            branch: null === $patchSet ? null : $patchSet['branch'],
            id: isset($journal['id']) ? (int) $journal['id'] : null,
        );
    }

    /**
     * The numbers of the issues the changes name: relations, the parent and subtasks.
     *
     * @return list<int>
     */
    public function issueNumbers(): array
    {
        $numbers = [];
        foreach ($this->changes as $change) {
            $namesIssue = [IssueFieldEnum::RELATION, IssueFieldEnum::PARENT, IssueFieldEnum::SUBTASK];
            if (in_array($change->field, $namesIssue, true)) {
                $numbers = [...$numbers, ...array_map(intval(...), array_filter([$change->from, $change->to]))];
            }
        }

        return $numbers;
    }

    /**
     * The journal without the changes that name an issue not in the list, null when nothing is left of it.
     *
     * @param array<int, true> $known the issues that may show, by number
     */
    public function withIssuesOf(array $known): ?self
    {
        $namesIssue = [IssueFieldEnum::RELATION, IssueFieldEnum::PARENT, IssueFieldEnum::SUBTASK];
        $changes = array_values(array_filter(
            $this->changes,
            static fn (IssueFieldChange $change): bool => !in_array($change->field, $namesIssue, true)
                || isset($known[(int) ($change->to ?? $change->from)]),
        ));
        if ('' === $this->notes && [] === $changes) {
            return null;
        }

        return new self(
            $this->author,
            $this->createdOn,
            $this->notes,
            $changes,
            $this->change,
            $this->patchSet,
            $this->branch,
            $this->id,
        );
    }

    public function isByBot(): bool
    {
        return in_array($this->author?->name, self::BOTS, true);
    }

    /**
     * Whether a person wrote a comment, the note of a bot is none.
     */
    public function hasComment(): bool
    {
        return '' !== $this->notes && !$this->isByBot();
    }

    /**
     * @param array<string, mixed> $detail a detail of a journal: property, name, old_value and new_value
     */
    private static function change(array $detail): ?IssueFieldChange
    {
        $property = (string) ($detail['property'] ?? '');
        $name = (string) ($detail['name'] ?? '');
        $from = self::value($detail['old_value'] ?? null);
        $to = self::value($detail['new_value'] ?? null);

        return match ($property) {
            'attr' => self::attribute($name, $from, $to),
            'cf' => new IssueFieldChange(
                IssueFieldEnum::CUSTOM_FIELD,
                self::CUSTOM_FIELDS[(int) $name] ?? 'Field ' . $name,
                $from,
                $to,
            ),
            'relation' => new IssueFieldChange(
                IssueFieldEnum::RELATION,
                IssueRelationEnum::tryFrom($name)?->label() ?? self::DUPLICATES[$name] ?? $name,
                $from,
                $to,
            ),
            'attachment' => new IssueFieldChange(IssueFieldEnum::ATTACHMENT, 'file', $from, $to),
            default => null,
        };
    }

    private static function attribute(string $name, ?string $from, ?string $to): IssueFieldChange
    {
        $named = static fn (?string $id, \Closure $label): ?string => null === $id ? null : ($label((int) $id) ?? $id);

        return match ($name) {
            'status_id' => new IssueFieldChange(
                IssueFieldEnum::STATUS,
                'status',
                $named($from, static fn (int $id): ?string => IssueStatusEnum::tryFrom($id)?->label()),
                $named($to, static fn (int $id): ?string => IssueStatusEnum::tryFrom($id)?->label()),
            ),
            'tracker_id' => new IssueFieldChange(
                IssueFieldEnum::TRACKER,
                'tracker',
                $named($from, static fn (int $id): ?string => TrackerEnum::tryFrom($id)?->label()),
                $named($to, static fn (int $id): ?string => TrackerEnum::tryFrom($id)?->label()),
            ),
            'priority_id' => new IssueFieldChange(
                IssueFieldEnum::PRIORITY,
                'priority',
                $named($from, static fn (int $id): ?string => PriorityEnum::tryFrom($id)?->label()),
                $named($to, static fn (int $id): ?string => PriorityEnum::tryFrom($id)?->label()),
            ),
            'assigned_to_id' => new IssueFieldChange(IssueFieldEnum::ASSIGNEE, 'assignee', $from, $to),
            'category_id' => new IssueFieldChange(IssueFieldEnum::CATEGORY, 'category', $from, $to),
            'fixed_version_id' => new IssueFieldChange(IssueFieldEnum::TARGET_VERSION, 'target version', $from, $to),
            'subject' => new IssueFieldChange(IssueFieldEnum::SUBJECT, 'subject', $from, $to),
            'description' => new IssueFieldChange(IssueFieldEnum::DESCRIPTION, 'description', null, null),
            'done_ratio' => new IssueFieldChange(
                IssueFieldEnum::DONE,
                'done',
                null === $from ? null : $from . ' %',
                null === $to ? null : $to . ' %',
            ),
            'parent_id' => new IssueFieldChange(IssueFieldEnum::PARENT, 'parent task', $from, $to),
            'child_id' => new IssueFieldChange(IssueFieldEnum::SUBTASK, 'subtask', $from, $to),
            default => new IssueFieldChange(IssueFieldEnum::OTHER, str_replace('_', ' ', $name), $from, $to),
        };
    }

    private static function value(mixed $value): ?string
    {
        $value = null === $value ? '' : trim((string) $value);

        return '' === $value ? null : $value;
    }
}
