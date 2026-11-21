<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

/**
 * Dates of the API: ISO 8601 in UTC, like "2026-11-21T09:30:00Z".
 */
final class ApiDate
{
    public const string DESCRIPTION = 'ISO 8601 in UTC.';

    public const string EXAMPLE = '2026-11-20T23:40:03Z';

    /**
     * @param string|null $indexDate a date as the index stores it, "Y/m/d H:i:s" in UTC
     */
    public static function fromIndex(?string $indexDate): ?string
    {
        if (null === $indexDate || '' === $indexDate) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y/m/d H:i:s', $indexDate, new \DateTimeZone('UTC'));

        return false === $date ? null : $date->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param string|null $forgeDate a date as Forge sends it, like "2026-11-20T23:40:03Z", the journals keep it so
     */
    public static function fromForge(?string $forgeDate): ?string
    {
        if (null === $forgeDate || '' === $forgeDate) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($forgeDate);
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
