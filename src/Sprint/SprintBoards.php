<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Elastic\Indices;
use App\Entity\SprintBoard;
use App\Enum\IssueStatusEnum;
use Symfony\Component\Yaml\Yaml;

/**
 * The issues of the sprint boards. A board selects them by its query, which is YAML of an Elasticsearch query.
 */
final readonly class SprintBoards
{
    private const int MAX_ISSUES = 10000;
    private const int PREVIEW_SIZE = 25;

    public function __construct(
        private Indices $indices,
    ) {
    }

    /**
     * @return array{open: int, closed: int, blocked: int, review: int, total: int, percentage?: float, reviewPercentage?: float, blockedPercentage?: float}
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function counts(string $query): array
    {
        $resultSet = $this->indices->issues()->createSearch([
            'query' => Yaml::parse($query),
            'size' => 0,
            'aggregations' => ['status' => ['terms' => ['field' => 'status.id']]],
        ])->search();
        $count = ['open' => 0, 'closed' => 0, 'blocked' => 0, 'review' => 0, 'total' => 0];
        foreach ((array) $resultSet->getAggregation('status')['buckets'] as $bucket) {
            $group = self::group(IssueStatusEnum::tryFrom((int) $bucket['key']));
            $count[$group] += $bucket['doc_count'];
            $count['total'] += $bucket['doc_count'];
        }
        if ($count['total'] > 0) {
            // Shares below 1 % have no bar of their own
            foreach (['reviewPercentage' => 'review', 'blockedPercentage' => 'blocked', 'percentage' => 'closed'] as $key => $group) {
                $share = round($count[$group] * 100.0 / $count['total']);
                if ($share >= 1 || 'percentage' === $key) {
                    $count[$key] = $share;
                }
            }
        }

        return $count;
    }

    /**
     * The cards of a board by column. An issue under review shows its Gerrit change, if the index has it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function cards(SprintBoard $board): array
    {
        $cards = BoardColumnEnum::each([]);
        $resultSet = $this->indices->issues()->createSearch([
            'query' => Yaml::parse((string) $board->getQuery()),
            'size' => self::MAX_ISSUES,
        ])->search();
        foreach ($resultSet->getResults() as $result) {
            $issue = $result->getData();
            $column = BoardColumnEnum::ofIssue($issue);
            $cards[$column->value][] = (BoardColumnEnum::REVIEW === $column ? $this->changeOf($issue) : null) ?? $issue;
        }

        return $cards;
    }

    /**
     * The issues a filter of the wizard selects: how many per column, and the latest of them.
     *
     * @return array{columns: array<string, int>, total: int, issues: list<array<string, mixed>>}
     */
    public function preview(BoardFilter $filter): array
    {
        $resultSet = $this->indices->issues()->createSearch([
            'query' => $filter->toQuery(),
            'size' => self::MAX_ISSUES,
            '_source' => ['id', 'subject', 'status', 'tracker'],
            'sort' => [['updated_on' => ['order' => 'desc']]],
        ])->search();
        $columns = BoardColumnEnum::each(0);
        $issues = [];
        foreach ($resultSet->getResults() as $result) {
            $issue = $result->getSource();
            $issue['column'] = BoardColumnEnum::ofIssue($issue)->value;
            ++$columns[$issue['column']];
            $issues[] = $issue;
        }

        return ['columns' => $columns, 'total' => count($issues), 'issues' => array_slice($issues, 0, self::PREVIEW_SIZE)];
    }

    /**
     * @param array<string, list<mixed>> $cards see cards()
     *
     * @return array{sections: array<string, array{classes: string, value: float, label: string}>, total: int}
     */
    public static function progress(array $cards): array
    {
        $counts = array_map(count(...), $cards);
        $total = array_sum($counts);
        $sections = [];
        foreach ($counts as $column => $count) {
            $key = strtolower($column);
            $sections[$key] = [
                'classes' => BoardColumnEnum::WIP->value === $column ? 'wip active progress-bar-striped' : $key,
                'value' => $total > 0 ? $count * 100 / $total : 0.0,
                'label' => $column,
            ];
        }

        return ['sections' => $sections, 'total' => $total];
    }

    private static function group(?IssueStatusEnum $status): string
    {
        return match (BoardColumnEnum::ofStatus($status)) {
            BoardColumnEnum::DONE => 'closed',
            BoardColumnEnum::REVIEW => 'review',
            BoardColumnEnum::BLOCKED => 'blocked',
            default => 'open',
        };
    }

    /**
     * The Gerrit change that the journal of the issue links, if the index has it.
     *
     * @param array<string, mixed> $issue
     *
     * @return array<string, mixed>|null
     */
    private function changeOf(array $issue): ?array
    {
        $notes = '';
        foreach ((array) ($issue['journals'] ?? []) as $journal) {
            if (preg_match('|It is available at https?://review\.typo3\.org/|', (string) ($journal['notes'] ?? ''))) {
                $notes = (string) $journal['notes'];
            }
        }
        if (1 !== preg_match('|https?://review\.typo3\.org/(?>(c/)?)(?>Packages/TYPO3\.CMS/\+/)?(?<number>\d{2,6})|', $notes, $matches)) {
            return null;
        }
        try {
            $change = $this->indices->reviews()->getDocument($matches['number'])->getData();
        } catch (\Exception) {
            return null;
        }
        $change['_redmine_issue_id'] = $issue['id'];

        return $change;
    }
}
