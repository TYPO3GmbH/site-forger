<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

/**
 * What the refresh of a single Gerrit change did, see GerritChangeImporter::refresh().
 */
enum ChangeRefreshResult
{
    case UPDATED;
    /**
     * Gerrit does not find the change in the project. Gerrit hardly deletes changes, so the index keeps it.
     */
    case NOT_FOUND;

    public function message(int $number): string
    {
        return match ($this) {
            self::UPDATED => sprintf('Change %d is up to date with Gerrit.', $number),
            self::NOT_FOUND => sprintf('Gerrit has no change %d in TYPO3 CMS. Forger shows it as before.', $number),
        };
    }

    /**
     * The type of the flash message.
     */
    public function flashType(): string
    {
        return match ($this) {
            self::UPDATED => 'success',
            self::NOT_FOUND => 'warning',
        };
    }
}
