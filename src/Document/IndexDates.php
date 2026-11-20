<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

/**
 * Reads the dates of the documents of the review and the issue index.
 */
final class IndexDates
{
    /**
     * The format of the dates in the review and the issue index.
     */
    private const string FORMAT = 'Y/m/d H:i:s';

    /**
     * When a change or issue document was last updated, else created.
     *
     * @param array<string, mixed> $document
     */
    public static function lastActivity(array $document): ?\DateTimeImmutable
    {
        foreach (['updated_on', 'created_on'] as $field) {
            $date = \DateTimeImmutable::createFromFormat(self::FORMAT, (string) ($document[$field] ?? ''));
            if (false !== $date) {
                return $date;
            }
        }

        return null;
    }
}
