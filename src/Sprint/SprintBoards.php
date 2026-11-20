<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Document\Change;
use App\Document\Issue;
use App\Entity\SprintBoard;
use App\Enum\IssueStatusEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;
use Symfony\Component\Yaml\Yaml;

/**
 * The issues of the sprint boards. A board selects them by its query, which is YAML of an Elasticsearch query.
 */
final readonly class SprintBoards
{
    private const int MAX_ISSUES = 10000;
    private const int PREVIEW_SIZE = 25;

    public function __construct(
        private IssueRepository $issues,
        private ChangeRepository $changes,
    ) {
    }

    /**
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function counts(string $query): BoardCount
    {
        $found = $this->issues->search([
            'query' => Yaml::parse($query),
            'size' => 0,
            'aggregations' => ['status' => ['terms' => ['field' => 'status.id']]],
        ]);
        $count = ['open' => 0, 'closed' => 0, 'blocked' => 0, 'review' => 0];
        foreach ($found->buckets('status') as $bucket) {
            $count[self::group(IssueStatusEnum::tryFrom((int) $bucket->key))] += $bucket->count;
        }

        return new BoardCount($count['open'], $count['closed'], $count['blocked'], $count['review']);
    }

    /**
     * The cards of a board by column. An issue under review shows its Gerrit change, if the index has it.
     *
     * @return array<string, ReadableCollection<int, Card>> by column
     */
    public function cards(SprintBoard $board): array
    {
        $cards = BoardColumnEnum::each([]);
        $found = $this->issues->search([
            'query' => Yaml::parse((string) $board->getQuery()),
            'size' => self::MAX_ISSUES,
        ]);
        foreach ($found as $issue) {
            $column = BoardColumnEnum::ofIssue($issue);
            $change = BoardColumnEnum::REVIEW === $column ? $this->changeOf($issue) : null;
            $cards[$column->value][] = new Card($issue, $column, $change);
        }

        return array_map(static fn (array $column): ArrayCollection => new ArrayCollection($column), $cards);
    }

    /**
     * The issues a filter of the wizard selects: how many per column, and the latest of them.
     */
    public function preview(BoardFilter $filter): BoardPreview
    {
        $found = $this->issues->search([
            'query' => $filter->toQuery(),
            'size' => self::MAX_ISSUES,
            '_source' => ['id', 'subject', 'status', 'tracker'],
            'sort' => [['updated_on' => ['order' => 'desc']]],
        ]);
        $columns = BoardColumnEnum::each(0);
        $cards = [];
        foreach ($found as $issue) {
            $column = BoardColumnEnum::ofIssue($issue);
            ++$columns[$column->value];
            $cards[] = new Card($issue, $column);
        }

        return new BoardPreview(
            $columns,
            count($cards),
            new ArrayCollection(array_slice($cards, 0, self::PREVIEW_SIZE)),
        );
    }

    /**
     * @param array<string, int> $counts how many cards each column has
     *
     * @return array{sections: array<string, array{classes: string, value: float, label: string}>, total: int}
     */
    public static function progress(array $counts): array
    {
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
     */
    private function changeOf(Issue $issue): ?Change
    {
        $notes = '';
        foreach ($issue->journals as $journal) {
            if (preg_match('|It is available at https?://review\.typo3\.org/|', (string) ($journal['notes'] ?? ''))) {
                $notes = (string) $journal['notes'];
            }
        }
        if (1 !== preg_match('|https?://review\.typo3\.org/(?>(c/)?)(?>Packages/TYPO3\.CMS/\+/)?(?<number>\d{2,6})|', $notes, $matches)) {
            return null;
        }

        return $this->changes->byNumber((int) $matches['number']);
    }
}
