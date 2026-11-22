<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Document\Change;
use App\Document\Issue;
use App\Elastic\SearchResult;
use App\Enum\StreamRangeEnum;
use App\Enum\WaitingForEnum;
use App\Repository\ChangeRepository;
use App\Repository\FollowRepository;
use App\Repository\IssueRepository;
use App\Search\OverviewQuery;
use App\Search\PeopleFacet;
use App\Search\StreamWindow;
use App\Stream\NewcomerFinder;
use App\Twig\UserTwigExtension;

/**
 * The people a user follows, with their names and avatars, the new contributors to follow, and for My Work which of
 * the work of the people needs help.
 */
final readonly class Following
{
    public const int AVATAR_SIZE = 48;

    public function __construct(
        private FollowRepository $follows,
        private UserDirectory $users,
        private ChangeRepository $changes,
        private IssueRepository $issues,
        private PeopleActivity $activity,
        private NewcomerFinder $newcomers,
    ) {
    }

    /**
     * The people who made their first step in the last 30 days and whom the user does not follow yet, the newest
     * first: whom a mentor may want to follow.
     *
     * @return list<SuggestedPerson>
     */
    public function newcomers(string $follower, int $limit): array
    {
        $followed = array_flip($this->follows->peopleOf($follower));
        $suggestions = [];
        $window = StreamWindow::ofRange(StreamRangeEnum::MONTH, StreamWindow::now(new \DateTimeZone('UTC')));
        foreach ($this->newcomers->find($window)->people as $newcomer) {
            $person = $newcomer->person;
            if (null === $person->forgeId && null === $person->gerritId) {
                continue;
            }
            $key = $this->users->keyOfPerson($person);
            if (isset($followed[$key]) || isset($suggestions[$key])) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat(
                'Y/m/d H:i:s',
                (string) $newcomer->date,
                new \DateTimeZone('UTC'),
            );
            $suggestions[$key] = new SuggestedPerson(
                $key,
                $person->name,
                $this->avatarOf($person),
                $newcomer->milestone->label(),
                false === $date ? null : $date,
            );
        }

        return array_slice(array_values($suggestions), 0, $limit);
    }

    /**
     * @return list<FollowedPerson> the latest followed first
     */
    public function peopleOf(string $follower): array
    {
        return array_map($this->person(...), $this->follows->peopleOf($follower));
    }

    /**
     * The people with what they did lately, for their cards, see PeopleActivity.
     *
     * @return list<FollowedPerson> the latest followed first
     */
    public function peopleWithActivityOf(string $follower, \DateTimeImmutable $now): array
    {
        $people = $this->peopleOf($follower);
        $activity = $this->activity->of(
            array_map(static fn (FollowedPerson $person): string => $person->key, $people),
            $now,
        );

        return array_map(
            static fn (FollowedPerson $person): FollowedPerson => new FollowedPerson(
                $person->key,
                $person->name,
                $person->avatar,
                $person->username,
                $activity[$person->key] ?? null,
            ),
            $people,
        );
    }

    /**
     * The open changes of the people that wait for the step. The search finds the same with the filters of
     * OverviewQuery::peopleChangesFilters().
     *
     * @param list<string> $keys the keys of the people, see UserDirectory
     *
     * @return SearchResult<Change>
     */
    public function waitingChanges(array $keys, WaitingForEnum $step, int $size): SearchResult
    {
        $owners = PeopleFacet::query('Owner', $keys, $this->users) ?? ['match_none' => new \stdClass()];

        return $this->changes->search(OverviewQuery::peopleChanges($owners, $step, $size));
    }

    /**
     * The issues of the people that nobody looked at yet. The search finds the same with the filters of
     * OverviewQuery::unansweredIssuesFilters().
     *
     * @param list<string> $keys the keys of the people, see UserDirectory
     *
     * @return SearchResult<Issue>
     */
    public function unansweredIssues(array $keys, \DateTimeImmutable $now, int $size): SearchResult
    {
        $authors = PeopleFacet::query('Author', $keys, $this->users) ?? ['match_none' => new \stdClass()];

        return $this->issues->search(OverviewQuery::unansweredIssues($authors, $now, $size));
    }

    private function person(string $key): FollowedPerson
    {
        $person = $this->users->personOf($key);
        $name = (string) $person?->name;

        return new FollowedPerson(
            $key,
            '' === $name ? $key : $name,
            $this->avatarOf($person),
            $person?->username,
        );
    }

    /**
     * The avatar of the person, the placeholder of the pages without one, so the rows line up.
     */
    private function avatarOf(?Person $person): string
    {
        return $this->users->avatarOfPerson($person, self::AVATAR_SIZE)
            ?? User::avatarUrl(UserTwigExtension::PLACEHOLDER, self::AVATAR_SIZE);
    }
}
