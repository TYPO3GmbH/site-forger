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
use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStatusEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\IssueSortQuery;
use Doctrine\Common\Collections\ArrayCollection;
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
     * The columns of a board, in their order, the cards in the order the board sets unless another is given. An
     * issue under review shows its Gerrit change, if the index has it.
     *
     * @return list<BoardColumn>
     */
    public function cards(SprintBoard $board, ?IssueSortEnum $sort = null): array
    {
        return $this->cardsOf((array) Yaml::parse((string) $board->getQuery()), $sort ?? $board->getCardSort());
    }

    /**
     * The columns of the issues a query finds, like those of a board, see cards(), each in the order of the sort.
     *
     * @param array<string, mixed> $query
     *
     * @return list<BoardColumn>
     */
    public function cardsOf(array $query, IssueSortEnum $sort = IssueSortEnum::UPDATED): array
    {
        $cards = [];
        $found = $this->issues->search([
            'query' => $query,
            'size' => self::MAX_ISSUES,
            'sort' => IssueSortQuery::sort($sort),
        ]);
        $open = $this->openChangesOf($found->map(static fn (Issue $issue): int => $issue->id)->toArray());
        foreach ($found as $issue) {
            $column = BoardColumnEnum::ofIssue($issue);
            // Without an open change, an issue under review still shows the one its notes link, like a merged one
            $change = $open[$issue->id] ?? (BoardColumnEnum::REVIEW === $column ? $this->changeOf($issue) : null);
            $cards[$column->name][] = new Card($issue, $column, $change);
        }

        return array_map(
            static fn (BoardColumnEnum $column): BoardColumn => new BoardColumn(
                $column,
                count($cards[$column->name] ?? []),
                new ArrayCollection($cards[$column->name] ?? []),
            ),
            BoardColumnEnum::cases(),
        );
    }

    /**
     * The issues a filter of the wizard selects: how many per column, and the latest of them.
     */
    public function preview(BoardFilter $filter): BoardPreview
    {
        $found = $this->issues->search([
            'query' => $filter->toQuery(),
            'size' => self::MAX_ISSUES,
            // What a row of an issue shows, without the comments, the files and the text
            '_source' => ['excludes' => ['journals', 'attachments', 'description']],
            'sort' => [['updated_on' => ['order' => 'desc']]],
        ]);
        $counts = [];
        $cards = [];
        foreach ($found as $issue) {
            $column = BoardColumnEnum::ofIssue($issue);
            $counts[$column->name] = ($counts[$column->name] ?? 0) + 1;
            $cards[] = new Card($issue, $column);
        }
        $columns = array_map(
            static fn (BoardColumnEnum $column): BoardColumn => new BoardColumn($column, $counts[$column->name] ?? 0),
            BoardColumnEnum::cases(),
        );

        return new BoardPreview(
            $columns,
            count($cards),
            new ArrayCollection(array_slice($cards, 0, self::PREVIEW_SIZE)),
        );
    }

    /**
     * @param list<BoardColumn> $columns
     *
     * @return array{sections: array<string, array{classes: string, value: float, label: string}>, total: int}
     */
    public static function progress(array $columns): array
    {
        $total = array_sum(array_map(static fn (BoardColumn $column): int => $column->count, $columns));
        $sections = [];
        foreach ($columns as $column) {
            $key = strtolower($column->column->label());
            $sections[$key] = [
                'classes' => BoardColumnEnum::WIP === $column->column ? 'wip active progress-bar-striped' : $key,
                'value' => $total > 0 ? $column->count * 100 / $total : 0.0,
                'label' => $column->column->label(),
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
     * The open change that resolves each issue, whatever the state of the issue: of several, like backports, the
     * one for main, else the one updated last.
     *
     * @param array<array-key, int> $issues
     *
     * @return array<int, Change> by the number of the issue
     */
    private function openChangesOf(array $issues): array
    {
        if ([] === $issues) {
            return [];
        }
        $found = $this->changes->search([
            'query' => ['bool' => ['filter' => [
                ['terms' => ['resolves' => array_values($issues)]],
                ['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]],
            ]]],
            'size' => self::MAX_ISSUES,
            'sort' => [['updated_on' => ['order' => 'desc']]],
        ]);
        $wanted = array_fill_keys($issues, true);
        $changes = [];
        foreach ($found as $change) {
            foreach ($change->resolves as $issue) {
                $known = $changes[$issue] ?? null;
                $better = null === $known || ('main' === $change->branch && 'main' !== $known->branch);
                if (isset($wanted[$issue]) && $better) {
                    $changes[$issue] = $change;
                }
            }
        }

        return $changes;
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
