<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Enum\StreamFigureEnum;
use App\Enum\StreamRangeEnum;
use App\Enum\StreamSourceEnum;
use App\People\UserDirectory;
use App\Search\StreamWindow;
use App\Stream\LeaderboardRow;
use App\Stream\LeaderboardSort;
use App\Stream\StreamStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The leaderboard of the activity, for the admins: the people with the most activity in a time window, by all
 * events, by a key figure, or by the lines added or removed by the changes merged, and how they moved since the
 * window before. A person is the TYPO3 account: its accounts on Forge and Gerrit count together. It counts as the
 * statistics do, see StreamStatistics::leaderboard().
 */
#[IsGranted('ROLE_ADMIN')]
class LeaderboardController extends AbstractController
{
    private const int AVATAR_SIZE = 32;

    public function __construct(
        private readonly StreamStatistics $statistics,
        private readonly UserDirectory $users,
    ) {
    }

    /**
     * The window is a range like "30d", or the days from "from" to "to" (YYYY-MM-DD), both included. Without a
     * valid one it is the last 7 days, as on the page of the statistics. "source" "forge" or "gerrit" counts only
     * the events of the source, without it all of them.
     */
    #[Route(path: '/management/leaderboard', name: 'management_leaderboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $now = StreamWindow::now(new \DateTimeZone(date_default_timezone_get()));
        $window = $this->days($request, $now);
        $range = null === $window
            ? StreamRangeEnum::tryFrom($request->query->getString('range')) ?? StreamRangeEnum::WEEK
            : null;
        $window ??= StreamWindow::ofRange($range ?? StreamRangeEnum::WEEK, $now);
        $source = StreamSourceEnum::tryFrom($request->query->getString('source'));
        $sort = LeaderboardSort::fromValue($request->query->getString('sort'))->forSource($source);
        $rows = $this->statistics->leaderboard($window, $sort, $source);
        $sourceParameters = null === $source ? [] : ['source' => $source->value];

        return $this->render('management/leaderboard.html.twig', [
            'rows' => $rows,
            'people' => array_map($this->person(...), $rows),
            'range' => $range,
            'ranges' => StreamRangeEnum::cases(),
            'window' => $window,
            'windowParameters' => $sourceParameters + (null === $range
                ? ['from' => $window->from->format('Y-m-d'), 'to' => $window->last()->format('Y-m-d')]
                : ['range' => $range->value]),
            'source' => $source,
            'sources' => StreamSourceEnum::cases(),
            'sort' => $sort->value(),
            'figures' => array_values(array_filter(
                StreamFigureEnum::cases(),
                static fn (StreamFigureEnum $figure): bool => null === $source || $figure->source() === $source,
            )),
        ]);
    }

    /**
     * The days picked, null without two valid days or with a first day still to come.
     */
    private function days(Request $request, \DateTimeImmutable $now): ?StreamWindow
    {
        $first = StreamWindow::day($request->query->getString('from'), $now->getTimezone());
        $last = StreamWindow::day($request->query->getString('to'), $now->getTimezone());
        if (null === $first || null === $last || min($first, $last) > $now) {
            return null;
        }

        return StreamWindow::between($first, $last, $now);
    }

    /**
     * The person as every page shows it, see UserDirectory::shown(): the key, the TYPO3 username where the index
     * of the users knows it, the name and the avatar.
     *
     * @return array{key: string, name: string, username: ?string, avatar: ?string}
     */
    private function person(LeaderboardRow $row): array
    {
        $person = $this->users->shown($row->person) ?? $row->person;

        return [
            'key' => $this->users->keyOfPerson($person),
            'name' => $person->name,
            'username' => $person->username,
            'avatar' => $this->users->avatarOfPerson($person, self::AVATAR_SIZE),
        ];
    }
}
