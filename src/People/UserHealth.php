<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Enum\SystemCheckEnum;
use App\Import\ForgeUserSync;

/**
 * The users on the system page: how many people cannot be mapped to an email, and why, see UserStatistics.
 *
 * People that "Update users" did not ask Forge for yet are no problem, the job asks for them run by run, see
 * ForgeUserSync. It needs attention only when the job does not get on, or when Forge does not know the users it
 * asks for.
 */
final class UserHealth
{
    /**
     * Without a user asked for in this time while users wait, the job does not get on: it runs every five minutes.
     */
    public const int STALE_AFTER_MINUTES = 60;

    /**
     * From this share of unknown users among those asked for, the token may not see the users any more: Forge
     * answers 403 then, as for a locked user.
     */
    private const float GONE_SHARE = 0.2;
    private const int GONE_MINIMUM = 20;

    /**
     * The reasons say why the people without an email have none.
     *
     * @return array{result: SystemCheckEnum, summary: string, reasons: string|null, hint: string|null}
     */
    public static function of(UserStatistics $statistics, \DateTimeImmutable $now): array
    {
        if (0 === $statistics->total) {
            return [
                'result' => SystemCheckEnum::ATTENTION,
                'summary' => 'No users yet.',
                'reasons' => null,
                'hint' => 'The next run of "Update users" takes them over from the issues.'
                    . ' If it does not, look at its runs.',
            ];
        }
        $check = [
            'summary' => self::summary($statistics),
            'reasons' => self::reasons($statistics),
        ];
        $waiting = $statistics->notAskedYet;
        $lastFetched = $statistics->lastFetched;
        $staleBefore = $now->modify(sprintf('-%d minutes', self::STALE_AFTER_MINUTES));
        if ($waiting > 0 && (null === $lastFetched || $lastFetched < $staleBefore)) {
            return $check + [
                'result' => SystemCheckEnum::PROBLEM,
                'hint' => sprintf(
                    'Forge was not asked for users %s. Look at the runs of "Update users": they fail, stop at a rate'
                    . ' limit, or do not start.',
                    null === $lastFetched ? 'ever' : 'since ' . $lastFetched->format('j M Y, H:i') . ' UTC',
                ),
            ];
        }
        $asked = $statistics->total - $waiting;
        if ($statistics->gone >= self::GONE_MINIMUM && $statistics->gone > $asked * self::GONE_SHARE) {
            return $check + [
                'result' => SystemCheckEnum::ATTENTION,
                'hint' => sprintf(
                    'Forge does not know %s of the %s users asked for. It answers 404 for deleted users and groups,'
                    . ' 403 for locked users, but also for every user when the token may not see users. Open'
                    . ' "/users/<number>.json" of a user below with the token of Forger: a user that exists and is not'
                    . ' locked must not give 403.',
                    self::number($statistics->gone),
                    self::number($asked),
                ),
            ];
        }
        $hours = (int) ceil($waiting / ForgeUserSync::PER_RUN * 5 / 60);

        return $check + [
            'result' => SystemCheckEnum::OK,
            'hint' => 0 === $waiting ? null : sprintf('"Update users" asks for the rest in about %d h.', $hours),
        ];
    }

    /**
     * The answers of Forge for the users it does not know, like "50 refused (403), 4 not found (404)".
     *
     * @param array<int, int> $byStatus see UserStatistics::$goneByStatus
     */
    public static function answers(array $byStatus): string
    {
        arsort($byStatus);
        $answers = [];
        foreach ($byStatus as $status => $count) {
            $answers[] = self::number($count) . ' ' . match ($status) {
                0 => 'not stored',
                403 => 'refused (403)',
                default => sprintf('not found (%d)', $status),
            };
        }

        return implode(', ', $answers);
    }

    private static function summary(UserStatistics $statistics): string
    {
        if (0 === $statistics->unmapped()) {
            return sprintf('All %s people are mapped to an email.', self::number($statistics->total));
        }

        return sprintf(
            '%s of %s people cannot be mapped to an email.',
            self::number($statistics->unmapped()),
            self::number($statistics->total),
        );
    }

    /**
     * Like "1,375 not asked for yet · 54 unknown to Forge (50 refused (403), 4 not found (404))", null for none.
     */
    private static function reasons(UserStatistics $statistics): ?string
    {
        $reasons = [];
        if ($statistics->notAskedYet > 0) {
            $reasons[] = self::number($statistics->notAskedYet) . ' not asked for yet';
        }
        if ($statistics->unknownToForge > 0) {
            $reasons[] = sprintf(
                '%s unknown to Forge (%s)',
                self::number($statistics->unknownToForge),
                self::answers($statistics->goneByStatus),
            );
        }
        if ($statistics->withoutEmail > 0) {
            $reasons[] = self::number($statistics->withoutEmail) . ' without an email on Forge';
        }

        return [] === $reasons ? null : implode(' · ', $reasons);
    }

    private static function number(int $number): string
    {
        return number_format($number, 0, '.', ',');
    }
}
