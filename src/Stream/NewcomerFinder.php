<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Document\Change;
use App\Document\Issue;
use App\Enum\StreamMilestoneEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\NewcomerQuery;
use App\Search\StreamWindow;

/**
 * Finds the firsts of new people in the issues and the changes themselves: their first issue, their first change,
 * their first change merged. Each first counts: somebody new on Forge and in Gerrit counts twice as a contribution.
 */
final readonly class NewcomerFinder
{
    /**
     * The firsts a page lists, the newest first: about a month of them.
     */
    public const int LIMIT = 20;

    /**
     * The large fields a newcomer does not read, see StreamNewcomer: the answer of Elasticsearch is a quarter as
     * large without them.
     */
    private const array ISSUE_FIELDS_LEFT_OUT = ['description', 'custom_fields', 'attachments'];
    private const array CHANGE_FIELDS_LEFT_OUT = [
        'file_details',
        'files',
        'commit_message',
        'commit_text',
        'comments',
        'chain',
        'merge_conflicts',
    ];

    public function __construct(
        private IssueRepository $issues,
        private ChangeRepository $changes,
        private StreamCache $cache,
    ) {
    }

    public function find(StreamWindow $window): StreamNewcomers
    {
        return $this->cache->get('newcomers', [$window], fn (): StreamNewcomers => $this->compute($window));
    }

    private function compute(StreamWindow $window): StreamNewcomers
    {
        $buckets = [];
        foreach (StreamMilestoneEnum::cases() as $milestone) {
            $buckets[$milestone->value] = $this->buckets($milestone, $window);
        }
        $tally = self::tally($buckets, $window);

        return new StreamNewcomers(
            $tally['contributions'],
            $tally['previous'],
            $tally['milestones'],
            $this->newcomers(array_slice($tally['firsts'], 0, self::LIMIT)),
        );
    }

    /**
     * Sorts the firsts of the people by the window: one before it counts for the window before, one in it counts
     * and is listed, one after a window that ended counts nowhere. A first merge is no contribution of its own:
     * the first change of the person counted already.
     *
     * @param array<string, list<array<string, mixed>>> $buckets the people of NewcomerQuery, by the milestone
     *
     * @return array{
     *     contributions: int,
     *     previous: int,
     *     milestones: array<string, int>,
     *     firsts: list<array{first: int, milestone: StreamMilestoneEnum, person: string}>,
     * } the firsts the newest first
     */
    public static function tally(array $buckets, StreamWindow $window): array
    {
        $from = $window->from->getTimestamp() * 1000;
        $until = $window->isOpen ? PHP_INT_MAX : $window->until->getTimestamp() * 1000;
        $firsts = [];
        $counts = [];
        $contributions = 0;
        $previous = 0;
        foreach (StreamMilestoneEnum::cases() as $milestone) {
            $count = 0;
            foreach ($buckets[$milestone->value] ?? [] as $bucket) {
                $first = (int) ($bucket[NewcomerQuery::FIRST]['value'] ?? 0);
                if ($first < $from) {
                    $previous += $milestone->isContribution() ? 1 : 0;
                } elseif ($first < $until) {
                    ++$count;
                    $firsts[] = ['first' => $first, 'milestone' => $milestone, 'person' => (string) $bucket['key']];
                }
            }
            $counts[$milestone->value] = $count;
            $contributions += $milestone->isContribution() ? $count : 0;
        }
        usort($firsts, static fn (array $a, array $b): int => $b['first'] <=> $a['first']);

        return [
            'contributions' => $contributions,
            'previous' => $previous,
            'milestones' => $counts,
            'firsts' => $firsts,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buckets(StreamMilestoneEnum $milestone, StreamWindow $window): array
    {
        $request = NewcomerQuery::request($milestone, $window);
        $result = StreamMilestoneEnum::FIRST_ISSUE === $milestone
            ? $this->issues->search($request)
            : $this->changes->search($request);

        return array_values((array) ($result->aggregation(NewcomerQuery::PEOPLE)['buckets'] ?? []));
    }

    /**
     * Loads the first issue or change of each person listed, one request per milestone.
     *
     * @param list<array{first: int, milestone: StreamMilestoneEnum, person: string}> $firsts
     *
     * @return list<StreamNewcomer>
     */
    private function newcomers(array $firsts): array
    {
        $documents = [];
        foreach (StreamMilestoneEnum::cases() as $milestone) {
            $people = array_column(
                array_filter($firsts, static fn (array $first): bool => $milestone === $first['milestone']),
                'person',
            );
            $documents[$milestone->value] = [] === $people ? [] : $this->firstsOf($milestone, $people);
        }
        $newcomers = [];
        foreach ($firsts as $first) {
            $document = $documents[$first['milestone']->value][$first['person']] ?? null;
            $newcomer = $document instanceof Issue
                ? StreamNewcomer::ofIssue($document)
                : StreamNewcomer::ofChange($first['milestone'], $document);
            if (null !== $newcomer) {
                $newcomers[] = $newcomer;
            }
        }

        return $newcomers;
    }

    /**
     * @param list<string> $people
     *
     * @return array<string, Issue|Change> the first document of each person, by the name of the person
     */
    private function firstsOf(StreamMilestoneEnum $milestone, array $people): array
    {
        $firsts = [];
        if (StreamMilestoneEnum::FIRST_ISSUE === $milestone) {
            $request = NewcomerQuery::firstsRequest($milestone, $people, self::ISSUE_FIELDS_LEFT_OUT);
            foreach ($this->issues->search($request) as $issue) {
                $firsts[(string) $issue->author?->name] = $issue;
            }

            return $firsts;
        }
        $request = NewcomerQuery::firstsRequest($milestone, $people, self::CHANGE_FIELDS_LEFT_OUT);
        foreach ($this->changes->search($request) as $change) {
            $firsts[(string) $change->owner] = $change;
        }

        return $firsts;
    }
}
