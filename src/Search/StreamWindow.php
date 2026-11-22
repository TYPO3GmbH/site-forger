<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Charts\TimeSeries;
use App\Enum\StreamIntervalEnum;
use App\Enum\StreamRangeEnum;

/**
 * The time window of the activity stream: one of StreamRangeEnum, days picked freely, or since the last visit of
 * the reader. The dates are in the time zone of the reader, so the days and hours of the charts are theirs.
 */
final readonly class StreamWindow
{
    /**
     * The interval of the chart over time by the length of the window, so it has between a few and some 60 bars.
     */
    private const int HOURS_UP_TO_DAYS = 2;
    private const int DAYS_UP_TO_DAYS = 120;

    /**
     * @param \DateTimeImmutable $from   included
     * @param \DateTimeImmutable $until  left out
     * @param bool               $isOpen whether the window ends now, so new events still come into it
     */
    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $until,
        public bool $isOpen,
        public ?StreamRangeEnum $range = null,
        public ?StreamIntervalEnum $chosenInterval = null,
    ) {
    }

    /**
     * The window with the interval the reader picked. One that does not fit the window leaves the one by its length.
     */
    public function withInterval(?StreamIntervalEnum $interval): self
    {
        $fits = null !== $interval && $interval->fits($this->lengthInDays());

        return new self($this->from, $this->until, $this->isOpen, $this->range, $fits ? $interval : null);
    }

    /**
     * The intervals the reader may pick for this window, see StreamIntervalEnum::fits().
     *
     * @return list<StreamIntervalEnum>
     */
    public function intervals(): array
    {
        $days = $this->lengthInDays();

        return array_values(array_filter(
            StreamIntervalEnum::cases(),
            static fn (StreamIntervalEnum $interval): bool => $interval->fits($days),
        ));
    }

    /**
     * Now at the full minute: a window that ends now keeps its key in StreamCache for a minute.
     */
    public static function now(\DateTimeZone $zone): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', $zone);

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'));
    }

    public static function ofRange(StreamRangeEnum $range, \DateTimeImmutable $now): self
    {
        return new self($range->firstDay($now), $now, true, $range);
    }

    /**
     * A year of the calendar in the time zone of $now. The current one ends now.
     */
    public static function ofYear(int $year, \DateTimeImmutable $now): self
    {
        $first = $now->setDate($year, 1, 1)->setTime(0, 0);

        return self::between($first, $first->setDate($year, 12, 31), $now);
    }

    /**
     * Days picked in the calendar, both included: "2026-11-02 to 2026-11-08", or one day alone. Null for a text
     * that names no day.
     */
    public static function ofDays(string $days, \DateTimeImmutable $now): ?self
    {
        $parts = array_map(trim(...), explode(' to ', $days, 2));
        $first = self::day($parts[0], $now->getTimezone());
        $last = self::day($parts[1] ?? $parts[0], $now->getTimezone());

        return null === $first || null === $last ? null : self::between($first, $last, $now);
    }

    /**
     * From the first to the last day, both included, in the time zone of $now. A window that reaches today ends now.
     */
    public static function between(\DateTimeImmutable $first, \DateTimeImmutable $last, \DateTimeImmutable $now): self
    {
        $zone = $now->getTimezone();
        $first = $first->setTimezone($zone)->setTime(0, 0);
        $last = $last->setTimezone($zone)->setTime(0, 0);
        if ($last < $first) {
            [$first, $last] = [$last, $first];
        }
        $until = $last->modify('+1 day');

        return $until > $now ? new self($first, $now, true) : new self($first, $until, false);
    }

    /**
     * A day as YYYY-MM-DD in the time zone, null for another text.
     */
    public static function day(string $text, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, $zone);

        return false === $day || $day->format('Y-m-d') !== $text ? null : $day;
    }

    /**
     * Since a time in the past, like the last visit. Null for a text that is no time, or one in the future.
     */
    public static function since(string $time, \DateTimeImmutable $now): ?self
    {
        try {
            $from = new \DateTimeImmutable($time);
        } catch (\Exception) {
            return null;
        }

        return $from < $now ? new self($from->setTimezone($now->getTimezone()), $now, true) : null;
    }

    /**
     * The window as many days before: what the key figures compare with. A window that ends now compares with the
     * same weekdays up to the same time of day, so a day just begun does not count against a whole one.
     */
    public function previous(): self
    {
        $shift = '-' . $this->wholeDays() . ' days';

        return new self($this->from->modify($shift), $this->until->modify($shift), false);
    }

    /**
     * The days the window touches, a day just begun included: 7 for the last 7 days early on the 7th day. Counted on
     * the calendar of its time zone, so a day of 23 or 25 hours is one day, too.
     */
    public function wholeDays(): int
    {
        $length = $this->from->diff($this->until);
        $days = (int) $length->days;
        $begun = $length->h > 0 || $length->i > 0 || $length->s > 0;

        return max(1, $begun ? $days + 1 : $days);
    }

    /**
     * The year up to the end of this window, for the calendar of the statistics: whatever the window, it shows a
     * year, so the window sits in its year.
     */
    public function year(): self
    {
        $first = $this->last()->setTime(0, 0)->modify('-1 year')->modify('+1 day');

        return new self(min($first, $this->from), $this->until, $this->isOpen);
    }

    /**
     * The days as the calendar shows them, "2026-11-02 to 2026-11-08".
     */
    public function days(): string
    {
        $first = $this->from->format('Y-m-d');
        $last = $this->until->modify('-1 second')->format('Y-m-d');

        return $first === $last ? $first : $first . ' to ' . $last;
    }

    /**
     * The interval of a bar of the chart over time: the one the reader picked, else an hour, a day, a week or a
     * month by the length of the window.
     */
    public function interval(): string
    {
        if (null !== $this->chosenInterval) {
            return $this->chosenInterval->value;
        }
        $days = $this->lengthInDays();

        return match (true) {
            $days <= self::HOURS_UP_TO_DAYS => TimeSeries::HOUR,
            $days <= self::DAYS_UP_TO_DAYS => TimeSeries::DAY,
            $days <= StreamIntervalEnum::WEEKS_UP_TO_DAYS => TimeSeries::WEEK,
            default => TimeSeries::MONTH,
        };
    }

    private function lengthInDays(): float
    {
        return ($this->until->getTimestamp() - $this->from->getTimestamp()) / 86400;
    }

    /**
     * The last moment of the window, for the last bar of the chart.
     */
    public function last(): \DateTimeImmutable
    {
        return $this->until->modify('-1 second');
    }

    /**
     * The documents of the window by a date: the date of an event, or another, like the day a change was closed.
     *
     * @return array<string, mixed>
     */
    public function query(string $field = 'date'): array
    {
        $utc = new \DateTimeZone('UTC');
        $range = ['gte' => $this->from->setTimezone($utc)->format('Y/m/d H:i:s')];
        // An open window takes the events of now, too, should a clock be a little ahead
        if (!$this->isOpen) {
            $range['lt'] = $this->until->setTimezone($utc)->format('Y/m/d H:i:s');
        }

        return ['range' => [$field => $range]];
    }
}
