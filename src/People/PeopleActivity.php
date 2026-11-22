<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Charts\TimeSeries;
use App\Enum\StreamRangeEnum;
use App\Repository\StreamRepository;
use App\Search\PeopleQuery;
use App\Search\StreamWindow;
use App\Stream\StreamPeople;

/**
 * What people did in a range of the activity, for their cards, see templates/_partials/person_card.html.twig. The
 * figures count as the statistics do, so a card shows the same as the statistics of the person.
 */
final readonly class PeopleActivity
{
    public const StreamRangeEnum RANGE = StreamRangeEnum::HALF_YEAR;

    public function __construct(
        private UserDirectory $users,
        private StreamRepository $stream,
    ) {
    }

    /**
     * @param list<string> $keys the keys of the people, see UserDirectory
     *
     * @return array<string, PersonActivity> by the key of the person, every key included
     */
    public function of(array $keys, \DateTimeImmutable $now, StreamRangeEnum $range = self::RANGE): array
    {
        if ([] === $keys) {
            return [];
        }
        $window = StreamWindow::ofRange($range, $now->setTimezone(new \DateTimeZone('UTC')));
        $weeks = TimeSeries::keys($window->from, $window->until, TimeSeries::WEEK);
        $numbers = [];
        foreach ($keys as $key) {
            $numbers[$key] = [
                'forge' => $this->users->numbersOf($key, 'forge'),
                'gerrit' => $this->users->numbersOf($key, 'gerrit'),
            ];
        }
        $found = StreamPeople::activity(
            $this->stream->search(PeopleQuery::activity($numbers, $window))->aggregation(PeopleQuery::PEOPLE),
            $weeks,
        );
        $activity = [];
        foreach ($keys as $key) {
            $activity[$key] = $found[$key] ?? PersonActivity::none(count($weeks));
        }

        return $activity;
    }
}
