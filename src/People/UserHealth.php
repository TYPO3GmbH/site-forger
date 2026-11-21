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
 * The users on the system page: of how many people Forger does not know the TYPO3 login, and why, see
 * UserStatistics. The login joins a person across Forge, Gerrit and Keycloak, so it counts first. The email follows
 * in a line of its own.
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
     * The reasons say why the people without a login have none.
     *
     * @return array{result: SystemCheckEnum, summary: string, reasons: string|null, emails: string|null,
     *               hint: string|null}
     */
    public static function of(UserStatistics $statistics, \DateTimeImmutable $now): array
    {
        if (0 === $statistics->total) {
            return [
                'result' => SystemCheckEnum::ATTENTION,
                'summary' => 'No users yet.',
                'reasons' => null,
                'emails' => null,
                'hint' => 'The next run of "Update users" copies them from the issues.'
                    . ' If it does not, check the runs of "Update users".',
            ];
        }
        $check = [
            'summary' => self::summary($statistics),
            'reasons' => self::reasons($statistics),
            'emails' => self::emails($statistics),
        ];
        $waiting = $statistics->notAskedYet;
        $lastFetched = $statistics->lastFetched;
        $staleBefore = $now->modify(sprintf('-%d minutes', self::STALE_AFTER_MINUTES));
        if ($waiting > 0 && (null === $lastFetched || $lastFetched < $staleBefore)) {
            return $check + [
                'result' => SystemCheckEnum::PROBLEM,
                'hint' => sprintf(
                    'Forger %s. Check the runs of "Update users": they may fail, hit a rate limit or not start.',
                    null === $lastFetched
                        ? 'has never asked Forge for users'
                        : 'has not asked Forge for users since ' . $lastFetched->format('j M Y, H:i') . ' UTC',
                ),
            ];
        }
        $asked = $statistics->total - $waiting;
        if ($statistics->gone >= self::GONE_MINIMUM && $statistics->gone > $asked * self::GONE_SHARE) {
            return $check + [
                'result' => SystemCheckEnum::ATTENTION,
                'hint' => sprintf(
                    'Forge does not know %s of the %s users that Forger asked for.'
                    . ' Forge answers 404 for deleted users and groups, and 403 for locked users.'
                    . ' If FORGE_ACCESS_TOKEN is missing or invalid, Forge answers 404 for every user.',
                    self::number($statistics->gone),
                    self::number($asked),
                ),
            ];
        }
        $hours = (int) ceil($waiting / ForgeUserSync::PER_RUN * 5 / 60);

        return $check + [
            'result' => SystemCheckEnum::OK,
            'hint' => 0 === $waiting
                ? null
                : sprintf('"Update users" asks Forge for the rest within about %d hours.', $hours),
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
                0 => 'without a stored answer',
                403 => 'refused (403)',
                default => sprintf('not found (%d)', $status),
            };
        }

        return implode(', ', $answers);
    }

    private static function summary(UserStatistics $statistics): string
    {
        if (0 === $statistics->withoutLogin()) {
            return sprintf('Forger knows the TYPO3 login of all %s people.', self::number($statistics->total));
        }

        return sprintf(
            'Forger knows the TYPO3 login of %s of %s people.',
            self::number($statistics->withLogin),
            self::number($statistics->total),
        );
    }

    /**
     * Like "1,375 not asked yet · 54 unknown to Forge (50 refused (403), 4 not found (404))", null for none.
     *
     * Forge tells the login of every user it knows. So the people without a login that Forge knows are not asked
     * for yet.
     */
    private static function reasons(UserStatistics $statistics): ?string
    {
        $reasons = [];
        $notAskedYet = $statistics->withoutLogin() - $statistics->unknownToForge;
        if ($notAskedYet > 0) {
            $reasons[] = self::number($notAskedYet) . ' not asked yet';
        }
        if ($statistics->unknownToForge > 0) {
            $reasons[] = sprintf(
                '%s unknown to Forge (%s)',
                self::number($statistics->unknownToForge),
                self::answers($statistics->goneByStatus),
            );
        }

        return [] === $reasons ? null : implode(' · ', $reasons);
    }

    /**
     * Like "An email address for 3,432 of 5,632 people · 771 with no email on Forge".
     */
    private static function emails(UserStatistics $statistics): string
    {
        $emails = sprintf(
            'An email address for %s of %s people',
            self::number($statistics->withEmail),
            self::number($statistics->total),
        );

        return 0 === $statistics->withoutEmail
            ? $emails
            : $emails . ' · ' . self::number($statistics->withoutEmail) . ' with no email on Forge';
    }

    private static function number(int $number): string
    {
        return number_format($number, 0, '.', ',');
    }
}
