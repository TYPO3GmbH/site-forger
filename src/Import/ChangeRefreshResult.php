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
 * What the refresh of a single Gerrit change did, see GerritChangeImporter::refresh().
 */
enum ChangeRefreshResult
{
    case UPDATED;
    /**
     * Gerrit does not show the change (any more): private now, deleted or in another project. Removed from the
     * index.
     */
    case REMOVED;
    /**
     * Gerrit does not show the change, and the index does not have it either.
     */
    case NOT_FOUND;

    public function message(int $number): string
    {
        return match ($this) {
            self::UPDATED => sprintf('Change %d is up to date with Gerrit.', $number),
            self::REMOVED => sprintf('Gerrit does not show change %d in TYPO3 CMS any more. Forger removed it.', $number),
            self::NOT_FOUND => sprintf('Gerrit has no change %d in TYPO3 CMS.', $number),
        };
    }

    /**
     * The type of the flash message.
     */
    public function flashType(): string
    {
        return match ($this) {
            self::UPDATED => 'success',
            self::REMOVED, self::NOT_FOUND => 'warning',
        };
    }
}
