<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Elastic\ElasticUtility;

/**
 * The values the issue index holds for each filter of the sprint board wizard, with their issue count.
 */
class IssueFilterChoices
{
    private const int MAX_VALUES = 1000;

    /** @var array<string, array<string, int>>|null */
    private ?array $values = null;

    /**
     * @return array<int|string, int> issue count by value, versions newest first, everything else alphabetical
     */
    public function valuesOf(string $field): array
    {
        $this->values ??= $this->load();

        return $this->values[$field] ?? [];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function load(): array
    {
        $fields = array_unique(array_values(BoardFilter::FIELDS));
        $aggregations = [];
        foreach ($fields as $field) {
            $aggregations[$field] = ['terms' => ['field' => $field, 'size' => self::MAX_VALUES]];
        }
        $resultSet = ElasticUtility::getIndexByName('issue')
            ->createSearch(['size' => 0, 'aggregations' => $aggregations])
            ->search();

        $values = [];
        foreach ($fields as $field) {
            $counts = [];
            foreach ((array) ($resultSet->getAggregation($field)['buckets'] ?? []) as $bucket) {
                // Issues without a value, e.g. without focus, cannot be filtered for
                if ('' !== trim((string) $bucket['key'])) {
                    $counts[(string) $bucket['key']] = (int) $bucket['doc_count'];
                }
            }
            // Values such as "13" turn into integer keys
            uksort($counts, static fn (int|string $a, int|string $b): int => strnatcasecmp((string) $a, (string) $b));
            if (str_contains($field, 'version')) {
                $counts = array_reverse($counts, true);
            }
            $values[$field] = $counts;
        }

        return $values;
    }
}
