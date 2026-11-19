<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Enum\IssueStatusEnum;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The filters of the sprint board wizard and the Elasticsearch query they stand for.
 *
 * An issue is on the board if it matches every filter that has values, and one of the values of each.
 * Only queries this class writes itself can be read back, every other query is edited as YAML.
 */
final class BoardFilter
{
    /**
     * The filters the wizard offers, by property, with the field of the issue index they filter.
     */
    public const array FIELDS = [
        'trackers' => 'tracker.name',
        'categories' => 'category.name',
        'fixedVersions' => 'fixed_version.name',
        'focuses' => 'focus.name',
        'tags' => 'tags',
        'typo3Versions' => 'typo3_version',
    ];

    /**
     * Value of {@see $closedVisibleDays} for boards that show closed issues forever.
     */
    public const int CLOSED_ALWAYS_VISIBLE = -1;

    private const string EXCLUDED_TRACKERS_FIELD = 'tracker.name';

    /** @var list<string> */
    public array $trackers = [];
    /** @var list<string> */
    public array $categories = [];
    /** @var list<string> */
    public array $fixedVersions = [];
    /** @var list<string> */
    public array $focuses = [];
    /** @var list<string> */
    public array $tags = [];
    /** @var list<string> */
    public array $typo3Versions = [];
    /** @var list<string> */
    public array $excludedTrackers = [];

    /**
     * Days closed issues stay on the board after their last update, 0 to hide them at once.
     */
    public int $closedVisibleDays = 30;

    public function hasFilter(): bool
    {
        foreach (array_keys(self::FIELDS) as $property) {
            if ([] !== $this->{$property}) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        $must = [];
        foreach (self::FIELDS as $property => $field) {
            if ([] !== $this->{$property}) {
                $must[] = ['terms' => [$field => self::normalize($this->{$property})]];
            }
        }

        $mustNot = [];
        if ([] !== $this->excludedTrackers) {
            $mustNot[] = ['terms' => [self::EXCLUDED_TRACKERS_FIELD => self::normalize($this->excludedTrackers)]];
        }
        $closed = ['terms' => ['status.name' => IssueStatusEnum::closedValues()]];
        if (0 === $this->closedVisibleDays) {
            $mustNot[] = $closed;
        } elseif ($this->closedVisibleDays > 0) {
            $mustNot[] = ['bool' => ['must' => [
                $closed,
                ['range' => ['updated_on' => ['lte' => 'now-' . $this->closedVisibleDays . 'd']]],
            ]]];
        }

        $bool = [];
        if ([] !== $must) {
            $bool['must'] = $must;
        }
        if ([] !== $mustNot) {
            $bool['must_not'] = $mustNot;
        }

        return ['bool' => $bool];
    }

    public function toYaml(): string
    {
        return Yaml::dump($this->toQuery(), 10, 2);
    }

    /**
     * The filters of a query this class wrote, null if the query was written or changed by hand.
     */
    public static function fromYaml(?string $yaml): ?self
    {
        if (null === $yaml || '' === trim($yaml)) {
            return new self();
        }
        try {
            $query = Yaml::parse($yaml);
        } catch (ParseException) {
            return null;
        }

        return is_array($query) ? self::fromQuery($query) : null;
    }

    /**
     * @param array<mixed> $query
     */
    public static function fromQuery(array $query): ?self
    {
        $filter = new self();
        $filter->closedVisibleDays = self::CLOSED_ALWAYS_VISIBLE;
        $fieldProperties = array_flip(self::FIELDS);

        foreach ((array) ($query['bool']['must'] ?? []) as $clause) {
            $terms = self::termsOf($clause);
            if (null === $terms || !isset($fieldProperties[$terms[0]])) {
                return null;
            }
            $filter->{$fieldProperties[$terms[0]]} = $terms[1];
        }
        foreach ((array) ($query['bool']['must_not'] ?? []) as $clause) {
            $terms = self::termsOf($clause);
            if (null !== $terms && self::EXCLUDED_TRACKERS_FIELD === $terms[0]) {
                $filter->excludedTrackers = $terms[1];
            } elseif (null !== $terms && 'status.name' === $terms[0]) {
                $filter->closedVisibleDays = 0;
            } elseif (1 === preg_match('/^now-([1-9]\d*)d$/', (string) ($clause['bool']['must'][1]['range']['updated_on']['lte'] ?? ''), $matches)) {
                $filter->closedVisibleDays = (int) $matches[1];
            } else {
                return null;
            }
        }

        // Whatever the clauses above did not understand makes the query differ from the one written back
        return $filter->hasFilter() && $filter->toQuery() === $query ? $filter : null;
    }

    /**
     * The filters a query written by hand comes closest to, for boards moving to the wizard.
     *
     * Filters on fields the wizard offers are taken over wherever they are nested, as are closed states left
     * out with or without a time range. Alternatives ("should") turn into values that all have to match.
     *
     * @param array<mixed> $query
     *
     * @return array{filter: self, dropped: list<string>} the clauses that were left out, as YAML
     */
    public static function approximate(array $query): array
    {
        $filter = new self();
        $filter->closedVisibleDays = self::CLOSED_ALWAYS_VISIBLE;
        $dropped = [];
        $filter->collect($query, false, $dropped);
        foreach (array_merge(array_keys(self::FIELDS), ['excludedTrackers']) as $property) {
            $filter->{$property} = self::normalize($filter->{$property});
        }

        return ['filter' => $filter, 'dropped' => $dropped];
    }

    /**
     * @param list<string> $dropped
     */
    private function collect(mixed $clause, bool $negated, array &$dropped): void
    {
        if (!is_array($clause) || [] === $clause) {
            return;
        }
        if (array_is_list($clause)) {
            foreach ($clause as $item) {
                $this->collect($item, $negated, $dropped);
            }

            return;
        }
        if (['bool'] === array_keys($clause) && is_array($clause['bool'])) {
            if ($negated && $this->collectClosed($clause['bool'])) {
                return;
            }
            foreach ($clause['bool'] as $occurrence => $clauses) {
                match ($occurrence) {
                    'must', 'filter', 'should' => $this->collect($clauses, $negated, $dropped),
                    'must_not' => $this->collect($clauses, !$negated, $dropped),
                    // Its meaning is lost once alternatives have to match all
                    'minimum_should_match' => null,
                    default => $dropped[] = Yaml::dump([$occurrence => $clauses], 0),
                };
            }

            return;
        }

        $terms = self::termOrTermsOf($clause);
        $fieldProperties = array_flip(self::FIELDS);
        if (null !== $terms && !$negated && isset($fieldProperties[$terms[0]])) {
            array_push($this->{$fieldProperties[$terms[0]]}, ...$terms[1]);
        } elseif (null !== $terms && $negated && self::EXCLUDED_TRACKERS_FIELD === $terms[0]) {
            array_push($this->excludedTrackers, ...$terms[1]);
        } elseif (null !== $terms && $negated && 'status.name' === $terms[0]
            && [] === array_diff($terms[1], IssueStatusEnum::closedValues())) {
            $this->closedVisibleDays = 0;
        } else {
            $dropped[] = Yaml::dump($clause, 0);
        }
    }

    /**
     * Takes over a left out bool query of closed states and a range of the last update, as the wizard writes it.
     *
     * @param array<mixed> $bool
     */
    private function collectClosed(array $bool): bool
    {
        $days = null;
        $closed = false;
        foreach ([...(array) ($bool['must'] ?? []), ...(array) ($bool['filter'] ?? []), ...(array) ($bool['should'] ?? [])] as $clause) {
            $terms = self::termOrTermsOf($clause);
            if (null !== $terms && 'status.name' === $terms[0] && [] === array_diff($terms[1], IssueStatusEnum::closedValues())) {
                $closed = true;
            } elseif (1 === preg_match('/^now-([1-9]\d*)d$/', (string) ($clause['range']['updated_on']['lte'] ?? ''), $matches)) {
                $days = (int) $matches[1];
            } else {
                return false;
            }
        }
        if (!$closed || [] !== array_diff(array_keys($bool), ['must', 'filter', 'should'])) {
            return false;
        }
        $this->closedVisibleDays = $days ?? 0;

        return true;
    }

    /**
     * @return array{0: string, 1: list<string>}|null
     */
    private static function termOrTermsOf(mixed $clause): ?array
    {
        if (is_array($clause) && ['term'] === array_keys($clause) && is_array($clause['term']) && 1 === count($clause['term'])) {
            $field = (string) array_key_first($clause['term']);
            $value = $clause['term'][$field];
            $value = is_array($value) ? ($value['value'] ?? null) : $value;

            return is_scalar($value) ? [$field, [(string) $value]] : null;
        }

        return self::termsOf($clause);
    }

    /**
     * @return array{0: string, 1: list<string>}|null
     */
    private static function termsOf(mixed $clause): ?array
    {
        if (!is_array($clause) || ['terms'] !== array_keys($clause) || !is_array($clause['terms']) || 1 !== count($clause['terms'])) {
            return null;
        }
        $field = (string) array_key_first($clause['terms']);
        $values = $clause['terms'][$field];

        return is_array($values) && array_is_list($values) ? [$field, array_map(strval(...), $values)] : null;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function normalize(array $values): array
    {
        $values = array_values(array_unique(array_map(strval(...), $values)));
        sort($values, SORT_NATURAL);

        return $values;
    }
}
