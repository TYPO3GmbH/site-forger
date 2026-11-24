<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

/**
 * Compares the changes of Gerrit with the index, a part each run, from the newest to the oldest and then again
 * from the newest, see GerritImport::sweep(). It finds what no delta finds: changes the index never had, and
 * changes Gerrit does not show any more. Gerrit can neither count changes nor search them by their numbers, so
 * the pages go back by the time of the last update ("before:").
 */
final class ChangeSweep
{
    /**
     * The most changes a run compares. The light list of Gerrit sends 500 in about a second, so a round over the
     * about 60,000 changes takes six runs, half an hour.
     */
    public const int LISTED = 10000;

    /**
     * A run lists no further page once it found this many changes to load. Each costs its details: a run loads
     * 1,000 in about two minutes.
     */
    public const int LOADED = 1000;

    /**
     * Where the next run goes on. Null starts at the newest change again, after the last page.
     *
     * Gerrit sends the time to the nanosecond, "before:" takes seconds. The next run starts one second after the
     * oldest change, so it lists the changes of that second again rather than skip those the run did not reach.
     *
     * @param \DateTimeImmutable|null $until  where this run started, null at the newest change
     * @param \DateTimeImmutable|null $oldest the update time of the oldest change the run listed
     * @param bool                    $more   whether Gerrit has older changes
     */
    public static function next(
        ?\DateTimeImmutable $until,
        ?\DateTimeImmutable $oldest,
        bool $more,
    ): ?\DateTimeImmutable {
        if (!$more || null === $oldest) {
            return null;
        }
        $next = $oldest->modify('+1 second');
        // A run of changes of one second would stand still: skip the rest of that second instead
        if (null !== $until && $next >= $until) {
            return $oldest->modify('-1 second');
        }

        return $next;
    }

    /**
     * The changes of the index in the time a run covers, to compare with those Gerrit listed. The oldest second
     * belongs to the next run, see next(). The last run of a round reaches back to the oldest change.
     *
     * @param \DateTimeImmutable|null $oldest the update time of the oldest change the run listed
     * @param \DateTimeImmutable|null $until  where the run started, null at the newest change
     * @param bool                    $more   whether Gerrit has older changes
     *
     * @return array<string, mixed>
     */
    public static function indexed(?\DateTimeImmutable $oldest, ?\DateTimeImmutable $until, bool $more): array
    {
        $range = [];
        if ($more && null !== $oldest) {
            $range['gt'] = self::indexDate($oldest);
        }
        if (null !== $until) {
            $range['lt'] = self::indexDate($until);
        }

        return [] === $range ? ['match_all' => new \stdClass()] : ['range' => ['updated_on' => $range]];
    }

    /**
     * The changes of the index this round compared already: those updated since where the next page starts. A share
     * of the index, for the progress of the round.
     *
     * @return array<string, mixed>
     */
    public static function compared(\DateTimeImmutable $until): array
    {
        return ['range' => ['updated_on' => ['gte' => self::indexDate($until)]]];
    }

    /**
     * A date of Gerrit ("2026-11-19 17:42:30.000000000"), in UTC.
     */
    public static function gerritTime(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable(substr($date, 0, 19), new \DateTimeZone('UTC'));
    }

    private static function indexDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y/m/d H:i:s');
    }
}
