<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * How long ago an open Gerrit change or Forge issue was last updated.
 *
 * Neither Gerrit nor Forge has such a state: the stage is derived from "updated_on", which the review and the
 * issue index both have. Bots only vote on uploaded patch sets, so an update means that someone worked on it,
 * and no update that nobody did. It is computed when shown, not stored, since it changes with time and not
 * with the document. Closed documents (merged, abandoned, resolved, ...) have no stage. The value is used in URLs.
 */
enum ActivityEnum: string
{
    case FRESH = 'fresh';
    case QUIET = 'quiet';
    case STALE = 'stale';
    case ORPHANED = 'orphaned';

    /**
     * The field of the review and the issue index the stage is derived from.
     */
    public const string FIELD = 'updated_on';

    /**
     * The format of the dates in the review and the issue index.
     */
    private const string DATE_FORMAT = 'Y/m/d H:i:s';

    public function label(): string
    {
        return match ($this) {
            self::FRESH => 'Fresh',
            self::QUIET => 'Quiet',
            self::STALE => 'Stale',
            self::ORPHANED => 'Orphaned',
        };
    }

    /**
     * Whether a document shows its stage: only when it lies still. That a document is fresh is the normal case and
     * would be noise in every row; the filter and the help still name it.
     */
    public function isNoteworthy(): bool
    {
        return self::FRESH !== $this;
    }

    /**
     * The key of the stage for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return 'activity-' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::FRESH => 'actions-bolt',
            self::QUIET => 'actions-clock',
            self::STALE => 'actions-moon',
            self::ORPHANED => 'actions-archive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FRESH => 'Activity within the last 14 days',
            self::QUIET => 'No activity for 14 to 60 days',
            self::STALE => 'No activity for 60 to 180 days',
            self::ORPHANED => 'No activity for more than 180 days',
        };
    }

    /**
     * Days without activity the stage starts at.
     */
    public function minimumDays(): int
    {
        return match ($this) {
            self::FRESH => 0,
            self::QUIET => 14,
            self::STALE => 60,
            self::ORPHANED => 180,
        };
    }

    /**
     * Days without activity the stage ends before, null for the last stage.
     */
    public function maximumDays(): ?int
    {
        return match ($this) {
            self::FRESH => self::QUIET->minimumDays(),
            self::QUIET => self::STALE->minimumDays(),
            self::STALE => self::ORPHANED->minimumDays(),
            self::ORPHANED => null,
        };
    }

    /**
     * The range of the stage in Elasticsearch date math: "gte" is included, "lt" is not.
     *
     * @return array{gte?: string, lt?: string}
     */
    public function range(): array
    {
        $range = [];
        if (null !== $this->maximumDays()) {
            $range['gte'] = 'now-' . $this->maximumDays() . 'd';
        }
        if ($this->minimumDays() > 0) {
            $range['lt'] = 'now-' . $this->minimumDays() . 'd';
        }

        return $range;
    }

    /**
     * The open Gerrit changes of the review index in this stage: merged and abandoned ones have none.
     *
     * @return array<string, mixed>
     */
    public function changeQuery(): array
    {
        return $this->openQuery(['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]]);
    }

    /**
     * The open Forge issues of the issue index in this stage: resolved, closed and rejected ones have none.
     *
     * @return array<string, mixed>
     */
    public function issueQuery(): array
    {
        return $this->openQuery(['terms' => ['status.name' => IssueStatusEnum::closedLabels()]]);
    }

    /**
     * A "filters" aggregation counting the open Gerrit changes per stage, see buckets().
     *
     * @return array<string, mixed>
     */
    public static function changeAggregation(): array
    {
        return self::aggregation(static fn (self $stage): array => $stage->changeQuery());
    }

    /**
     * A "filters" aggregation counting the open Forge issues per stage, see buckets().
     *
     * @return array<string, mixed>
     */
    public static function issueAggregation(): array
    {
        return self::aggregation(static fn (self $stage): array => $stage->issueQuery());
    }

    /**
     * The result of changeAggregation() or issueAggregation() as the filter list shows aggregations: a bucket per
     * stage in the order of the stages, with the label as key.
     *
     * @param array<string, mixed> $result
     *
     * @return array{buckets: list<array{key: string, doc_count: int}>}
     */
    public static function buckets(array $result): array
    {
        return ['buckets' => array_map(
            static fn (self $stage): array => ['key' => $stage->label(), 'doc_count' => (int) ($result['buckets'][$stage->label()]['doc_count'] ?? 0)],
            self::cases(),
        )];
    }

    /**
     * @param array<string, mixed> $closed
     *
     * @return array<string, mixed>
     */
    private function openQuery(array $closed): array
    {
        return ['bool' => [
            'filter' => [['range' => [self::FIELD => $this->range()]]],
            'must_not' => [$closed],
        ]];
    }

    /**
     * @param callable(self): array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private static function aggregation(callable $query): array
    {
        $filters = [];
        foreach (self::cases() as $stage) {
            $filters[$stage->label()] = $query($stage);
        }

        return ['filters' => ['filters' => $filters]];
    }

    public static function tryFromLabel(string $label): ?self
    {
        foreach (self::cases() as $stage) {
            if ($stage->label() === $label) {
                return $stage;
            }
        }

        return null;
    }

    public static function fromDate(\DateTimeInterface $lastActivity, \DateTimeInterface $now): self
    {
        $now = \DateTimeImmutable::createFromInterface($now);
        foreach ([self::ORPHANED, self::STALE, self::QUIET] as $stage) {
            if ($lastActivity < $now->modify('-' . $stage->minimumDays() . ' days')) {
                return $stage;
            }
        }

        return self::FRESH;
    }

    /**
     * The stage of a change document of the review index, null if the change is closed or has no date.
     *
     * @param array<string, mixed> $change
     */
    public static function fromChange(array $change, \DateTimeInterface $now): ?self
    {
        if (ReviewStateEnum::fromChange($change)->isClosed()) {
            return null;
        }
        $lastActivity = self::lastActivity($change);

        return null === $lastActivity ? null : self::fromDate($lastActivity, $now);
    }

    /**
     * The stage of an issue document of the issue index, null if the issue is closed or has no date.
     * Statuses Forge does not know count as open.
     *
     * @param array<string, mixed> $issue
     */
    public static function fromIssue(array $issue, \DateTimeInterface $now): ?self
    {
        $status = isset($issue['status']['id'])
            ? IssueStatusEnum::tryFrom((int) $issue['status']['id'])
            : IssueStatusEnum::tryFromLabel((string) ($issue['status']['name'] ?? ''));
        if (null !== $status && $status->isClosed()) {
            return null;
        }
        $lastActivity = self::lastActivity($issue);

        return null === $lastActivity ? null : self::fromDate($lastActivity, $now);
    }

    /**
     * When a change or issue document was last updated, else created.
     *
     * @param array<string, mixed> $document
     */
    public static function lastActivity(array $document): ?\DateTimeImmutable
    {
        foreach ([self::FIELD, 'created_on'] as $field) {
            $date = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, (string) ($document[$field] ?? ''));
            if (false !== $date) {
                return $date;
            }
        }

        return null;
    }
}
