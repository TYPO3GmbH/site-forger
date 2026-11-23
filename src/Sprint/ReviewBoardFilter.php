<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Enum\ActivityEnum;
use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewStatusEnum;
use App\Search\ActivityQuery;
use App\Search\ReviewLists;
use App\Search\ReviewSizeQuery;

/**
 * The filters of a sprint board of the type "Reviews", see BoardTypeEnum: which open changes of Gerrit it shows.
 *
 * A change is on the board if it matches every filter that has values, and one of the values of each. The board
 * stores the filters as toArray() writes them.
 */
final class ReviewBoardFilter
{
    /**
     * The filters with a list of values of a field, by property, with the field of the review index they filter.
     */
    public const array FIELDS = [
        'tags' => 'reviewtags',
        'branches' => 'branch',
        'topics' => 'topic',
        'owners' => 'owner_username',
        'releases' => 'releases',
        'mergeable' => 'mergeable',
    ];

    /**
     * Every filter with a list of values, as toArray() stores them.
     */
    private const array LISTS = [
        'paths',
        'tags',
        'branches',
        'releases',
        'topics',
        'owners',
        'sizes',
        'activities',
        'mergeable',
    ];

    /**
     * Folders, files and patterns, see ReviewLists::anyFileQuery(): the changes that touch any of them.
     *
     * @var list<string>
     */
    public array $paths = [];

    /**
     * The tags of the subject, like "BUGFIX" of "[BUGFIX] …".
     *
     * @var list<string>
     */
    public array $tags = [];

    /** @var list<string> */
    public array $branches = [];

    /** @var list<string> */
    public array $topics = [];

    /**
     * The usernames of the owners of the changes.
     *
     * @var list<string>
     */
    public array $owners = [];

    /**
     * The versions of "Releases:" in the commit message, like "main" and "13.4".
     *
     * @var list<string>
     */
    public array $releases = [];

    /**
     * Whether Gerrit can merge the changes: "yes", or "no" for a merge conflict.
     *
     * @var list<string>
     */
    public array $mergeable = [];

    /**
     * The sizes of the changes by their lines, as ReviewSizeEnum labels them, like "Easy".
     *
     * @var list<string>
     */
    public array $sizes = [];

    /**
     * How long ago the changes were updated, as ActivityEnum labels the stages, like "Stale".
     *
     * @var list<string>
     */
    public array $activities = [];

    /**
     * A query for the issue index as YAML, as BoardFilter writes it: the changes that name an issue it finds in
     * "Resolves:" or "Related:". ReviewBoards applies it, the query of toQuery() does not know the issues.
     */
    public ?string $issueQuery = null;

    /**
     * Whether the board also shows the changes their owners marked as work in progress.
     */
    public bool $workInProgress = false;

    public function hasFilter(): bool
    {
        foreach (self::LISTS as $property) {
            if ([] !== $this->{$property}) {
                return true;
            }
        }

        return $this->filtersIssues();
    }

    /**
     * Whether the issues the changes name are filtered. The form sets an empty query when they are not. Not named
     * "has…": the form would read the property through it.
     */
    public function filtersIssues(): bool
    {
        return null !== $this->issueQuery && '' !== trim($this->issueQuery);
    }

    /**
     * The open changes of the filters, without the one of their issues, see {@see $issueQuery}.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        $filter = [['query_string' => ['default_field' => 'reviewstatus', 'query' => ReviewStatusEnum::NEW->value]]];
        $files = ReviewLists::anyFileQuery($this->paths);
        if (null !== $files) {
            $filter[] = $files;
        }
        foreach (self::FIELDS as $property => $field) {
            if ([] !== $this->{$property}) {
                $filter[] = ['terms' => [$field => $this->{$property}]];
            }
        }
        $sizes = array_filter(array_map(ReviewSizeEnum::tryFromLabel(...), $this->sizes));
        if ([] !== $sizes) {
            $filter[] = self::anyOf(array_map(ReviewSizeQuery::changes(...), array_values($sizes)));
        }
        $stages = array_filter(array_map(ActivityEnum::tryFromLabel(...), $this->activities));
        if ([] !== $stages) {
            $filter[] = self::anyOf(array_map(ActivityQuery::changes(...), array_values($stages)));
        }
        $bool = ['filter' => $filter];
        if (!$this->workInProgress) {
            $bool['must_not'] = [['term' => ['work_in_progress' => true]]];
        }

        return ['bool' => $bool];
    }

    /**
     * @param list<array<string, mixed>> $queries
     *
     * @return array<string, mixed>
     */
    private static function anyOf(array $queries): array
    {
        return ['bool' => ['should' => $queries, 'minimum_should_match' => 1]];
    }

    /**
     * @return array<string, list<string>|string|bool> the filters that are set
     */
    public function toArray(): array
    {
        $values = [];
        foreach (self::LISTS as $property) {
            if ([] !== $this->{$property}) {
                $values[$property] = $this->{$property};
            }
        }
        if ($this->filtersIssues()) {
            $values['issueQuery'] = (string) $this->issueQuery;
        }
        if ($this->workInProgress) {
            $values['workInProgress'] = true;
        }

        return $values;
    }

    /**
     * @param array<mixed>|null $values as toArray() wrote them
     */
    public static function fromArray(?array $values): self
    {
        $filter = new self();
        foreach (self::LISTS as $property) {
            $filter->{$property} = self::normalize((array) ($values[$property] ?? []));
        }
        $issueQuery = $values['issueQuery'] ?? null;
        $filter->issueQuery = is_string($issueQuery) && '' !== trim($issueQuery) ? $issueQuery : null;
        $filter->workInProgress = true === ($values['workInProgress'] ?? false);

        return $filter;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string> each value once, without empty ones
     */
    public static function normalize(array $values): array
    {
        $values = array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $values,
        );

        return array_values(array_unique(array_filter($values, static fn (string $value): bool => '' !== $value)));
    }
}
