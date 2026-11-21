<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Scheduler;

use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * Once a week on a weekday at a time, like "Monday 03:17", in the time zone of the schedule (JobSchedule::TIMEZONE),
 * whatever the time zone of the server: the command runs page shows the times in it too.
 *
 * The periodical trigger of the scheduler counts in seconds: "every week" would move by an hour with the change
 * to and from summer time. modify() goes by the calendar, the time stays.
 */
final readonly class WeeklyTrigger implements TriggerInterface
{
    private const array WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param string $weekday in English, like "monday"
     * @param string $time    "HH:MM"
     */
    public function __construct(
        private string $weekday,
        private string $time,
        private string $timezone = JobSchedule::TIMEZONE,
    ) {
        if (!in_array(strtolower($weekday), self::WEEKDAYS, true) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new \InvalidArgumentException(sprintf('"%s %s" is no weekday with a time like "monday 03:17"', $weekday, $time));
        }
    }

    public function getNextRunDate(\DateTimeImmutable $run): \DateTimeImmutable
    {
        $run = $run->setTimezone(new \DateTimeZone($this->timezone));
        // The run of this week if it is still to come, the one of next week otherwise
        $next = $run->modify($this->weekday . ' this week ' . $this->time);

        return $next > $run ? $next : $run->modify($this->weekday . ' next week ' . $this->time);
    }

    public function __toString(): string
    {
        return sprintf('every %s at %s', ucfirst(strtolower($this->weekday)), $this->time);
    }
}
