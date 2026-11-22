<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

use App\Enum\StreamIntervalEnum;
use App\Enum\StreamKindEnum;
use App\Enum\StreamRangeEnum;
use App\Enum\StreamSourceEnum;
use App\Enum\StreamSplitEnum;
use App\People\UserDirectory;
use App\Search\StreamFilter;
use App\Search\StreamWindow;
use Symfony\Component\HttpFoundation\Request;

/**
 * The parameters of the activity endpoints: the time window in the time zone of the reader, and the filter. The
 * elements of the activity pages send the same, see assets/ts/activity/.
 */
final readonly class ActivityFilters
{
    private const string BRANCH = '/^[\w.\/-]{1,100}$/';

    private const string PERSON_KEY = '/^(forge|gerrit):\d{1,9}$/';

    /**
     * The people "people" takes at most: as many as a user may follow, see FollowRepository::LIMIT.
     */
    public const int MAX_PEOPLE = 100;

    /**
     * A key of "people": "forge:<number>", "gerrit:<number>", a number, or a username, which has no spaces.
     */
    private const string PEOPLE_KEY = '/^(?:(?:forge|gerrit):\d{1,9}|\d{1,9}|[\w.@+-]{1,255})$/u';

    private const array PEOPLE_FORMS = ['forge:<number>', 'gerrit:<number>', 'a username'];

    /**
     * The first year with activity: the oldest issue of the TYPO3 Core on Forge is of April 2004. The calendars of
     * the pages start with it, see StreamTwigExtension::definitions().
     */
    public const int FIRST_YEAR = 2004;

    public function __construct(
        public StreamFilter $filter,
        public StreamWindow $window,
        public ?string $personKey,
    ) {
    }

    /**
     * @throws InvalidParameter
     */
    public static function fromRequest(Request $request, UserDirectory $users): self
    {
        $now = StreamWindow::now(self::timeZone($request));
        $window = self::window($request, $now)->withInterval(self::interval($request));
        $person = ListParameters::optional($request, 'person');
        $people = ListParameters::optional($request, 'people');
        if (null !== $person && null !== $people) {
            throw InvalidParameter::notTogether('person', 'people');
        }
        $personKey = null === $person ? null : self::personKey($person, $users);
        $byNumber = null !== $personKey;
        $branch = ListParameters::optional($request, 'branch');
        if (null !== $branch && 1 !== preg_match(self::BRANCH, $branch)) {
            throw InvalidParameter::notOneOf('branch', $branch, ['a branch like main or 13.4']);
        }
        [$forgeIds, $gerritIds] = null === $people
            ? [
                $byNumber ? $users->numbersOf($personKey, 'forge') : [],
                $byNumber ? $users->numbersOf($personKey, 'gerrit') : [],
            ]
            : self::numbersOfPeople($people, $users);

        return new self(
            new StreamFilter(
                window: $window,
                source: self::source($request),
                kinds: self::kinds($request),
                branch: $branch,
                forgeIds: $forgeIds,
                gerritIds: $gerritIds,
                nameWords: $byNumber || null === $person ? [] : (PersonFilter::fromValue($person)->words ?? []),
                bots: ListParameters::flag($request, 'bots'),
            ),
            $window,
            $personKey,
        );
    }

    /**
     * @throws InvalidParameter
     */
    public static function split(Request $request): StreamSplitEnum
    {
        return ListParameters::choice($request, 'split', StreamSplitEnum::class, StreamSplitEnum::TOTAL);
    }

    /**
     * @throws InvalidParameter
     */
    private static function timeZone(Request $request): \DateTimeZone
    {
        $name = ListParameters::optional($request, 'timeZone') ?? 'UTC';
        if (!in_array($name, \DateTimeZone::listIdentifiers(), true) && 'UTC' !== $name) {
            throw InvalidParameter::notATimeZone('timeZone');
        }

        return new \DateTimeZone($name);
    }

    /**
     * "year" before "from" and "to" before "since" before "range", 7 days without any of them.
     *
     * @throws InvalidParameter
     */
    private static function window(Request $request, \DateTimeImmutable $now): StreamWindow
    {
        $year = ListParameters::optional($request, 'year');
        if (null !== $year) {
            $current = (int) $now->format('Y');
            if (1 !== preg_match('/^\d{4}$/', $year) || (int) $year < self::FIRST_YEAR || (int) $year > $current) {
                throw InvalidParameter::outOfRange('year', self::FIRST_YEAR, $current);
            }

            return StreamWindow::ofYear((int) $year, $now);
        }
        $from = ListParameters::optional($request, 'from');
        $to = ListParameters::optional($request, 'to');
        if (null !== $from || null !== $to) {
            // An end left out is open: from the first day of Forge, or up to now
            $first = null === $from ? $now->setDate(self::FIRST_YEAR, 1, 1) : self::day('from', $from, $now);
            $last = null === $to ? $now : self::day('to', $to, $now);

            return StreamWindow::between($first, $last, $now);
        }
        $since = ListParameters::optional($request, 'since');
        if (null !== $since) {
            return StreamWindow::since($since, $now) ?? throw InvalidParameter::notATime('since');
        }

        return StreamWindow::ofRange(
            ListParameters::choice($request, 'range', StreamRangeEnum::class, StreamRangeEnum::WEEK),
            $now,
        );
    }

    /**
     * @throws InvalidParameter
     */
    private static function interval(Request $request): ?StreamIntervalEnum
    {
        return null === ListParameters::optional($request, 'interval')
            ? null
            : ListParameters::choice($request, 'interval', StreamIntervalEnum::class, StreamIntervalEnum::DAY);
    }

    /**
     * @throws InvalidParameter
     */
    private static function day(string $name, string $value, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return StreamWindow::day($value, $now->getTimezone()) ?? throw InvalidParameter::notADay($name);
    }

    /**
     * @throws InvalidParameter
     */
    private static function source(Request $request): ?StreamSourceEnum
    {
        return null === ListParameters::optional($request, 'source')
            ? null
            : ListParameters::choice($request, 'source', StreamSourceEnum::class, StreamSourceEnum::FORGE);
    }

    /**
     * The kinds of "kind", any of them, separated by commas: "voted,merged".
     *
     * @return list<StreamKindEnum>
     *
     * @throws InvalidParameter
     */
    private static function kinds(Request $request): array
    {
        $kinds = [];
        $values = array_filter(array_map(trim(...), explode(',', ListParameters::optional($request, 'kind') ?? '')));
        $allowed = array_map(static fn (StreamKindEnum $kind): string => $kind->value, StreamKindEnum::cases());
        foreach ($values as $value) {
            $kinds[] = StreamKindEnum::tryFrom($value) ?? throw InvalidParameter::notOneOf('kind', $value, $allowed);
        }

        return array_values(array_unique($kinds, SORT_REGULAR));
    }

    /**
     * The numbers on Forge and on Gerrit of the people of "people": keys separated by commas, as "person" takes
     * them, but no words of a name. A key of nobody known finds nothing: a list of followed people keeps working
     * when one of them is gone, see FollowRepository.
     *
     * @return array{list<int>, list<int>}
     *
     * @throws InvalidParameter
     */
    private static function numbersOfPeople(string $people, UserDirectory $users): array
    {
        $keys = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $people)))));
        if (count($keys) > self::MAX_PEOPLE) {
            throw InvalidParameter::tooMany('people', self::MAX_PEOPLE);
        }
        $forgeIds = [];
        $gerritIds = [];
        foreach ($keys as $key) {
            if (1 !== preg_match(self::PEOPLE_KEY, $key)) {
                throw InvalidParameter::notOneOf('people', $key, self::PEOPLE_FORMS);
            }
            $personKey = self::personKey($key, $users);
            if (null === $personKey) {
                continue;
            }
            $forgeIds = [...$forgeIds, ...$users->numbersOf($personKey, 'forge')];
            $gerritIds = [...$gerritIds, ...$users->numbersOf($personKey, 'gerrit')];
        }
        if ([] === $forgeIds && [] === $gerritIds) {
            // Without any number the filter would find everybody. No account has the number 0.
            $forgeIds = [0];
        }

        return [array_values(array_unique($forgeIds)), array_values(array_unique($gerritIds))];
    }

    /**
     * The key of the person in UserDirectory: "forge:<number>" or "gerrit:<number>" as given, a number alone is
     * the user on Forge, as in the other endpoints. Null for words of a name.
     */
    private static function personKey(string $person, UserDirectory $users): ?string
    {
        if (1 === preg_match(self::PERSON_KEY, $person)) {
            return $person;
        }
        if (1 === preg_match('/^\d{1,9}$/', $person)) {
            return 'forge:' . $person;
        }

        return null === $users->personOf($person) || str_contains($person, ' ') ? null : $person;
    }
}
