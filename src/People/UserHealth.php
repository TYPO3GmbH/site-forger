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
 * The users on the system page in one line: whether "Update users" does its work, see ForgeUserSync. UserRepository
 * still waiting for Forge are no problem, the job asks for them run by run. It needs attention only when it does
 * not get on, or when Forge does not know the users it asks for.
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
     * @param array{total: int, withAvatar: int, waiting: int, gone: int, lastFetched: \DateTimeImmutable|null} $statistics see UserRepository::statistics()
     *
     * @return array{result: SystemCheckEnum, summary: string, hint: string|null}
     */
    public static function of(array $statistics, \DateTimeImmutable $now): array
    {
        ['total' => $total, 'withAvatar' => $withAvatar, 'waiting' => $waiting, 'gone' => $gone, 'lastFetched' => $lastFetched] = $statistics;
        $asked = $total - $waiting;
        if (0 === $total) {
            return [
                'result' => SystemCheckEnum::ATTENTION,
                'summary' => 'No users yet.',
                'hint' => 'The next run of "Update users" takes them over from the issues. If it does not, look at its runs.',
            ];
        }
        $summary = sprintf('Avatars for %s of %s people.', self::number($withAvatar), self::number($total));
        $staleBefore = $now->modify(sprintf('-%d minutes', self::STALE_AFTER_MINUTES));
        if ($waiting > 0 && (null === $lastFetched || $lastFetched < $staleBefore)) {
            return [
                'result' => SystemCheckEnum::PROBLEM,
                'summary' => $summary . sprintf(' %s wait for Forge, which was not asked for %s.', self::number($waiting), null === $lastFetched ? 'ever' : 'since ' . $lastFetched->format('j M Y, H:i') . ' UTC'),
                'hint' => 'Look at the runs of "Update users": they fail, stop at a rate limit, or do not start.',
            ];
        }
        if ($gone >= self::GONE_MINIMUM && $gone > $asked * self::GONE_SHARE) {
            return [
                'result' => SystemCheckEnum::ATTENTION,
                'summary' => $summary . sprintf(' Forge does not know %s of the %s users asked for.', self::number($gone), self::number($asked)),
                'hint' => 'Forge answers so for deleted and locked users and for groups, but also when the token may not see users any more: check that it still sees them.',
            ];
        }
        $hours = (int) ceil($waiting / ForgeUserSync::PER_RUN * 5 / 60);

        return [
            'result' => SystemCheckEnum::OK,
            'summary' => $summary . (0 === $waiting ? ' Forge was asked for all of them.' : sprintf(' %s wait for Forge, all asked for in about %d h.', self::number($waiting), $hours)),
            'hint' => null,
        ];
    }

    private static function number(int $number): string
    {
        return number_format($number, 0, '.', ',');
    }
}
