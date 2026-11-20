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
 * What the refresh of a single issue did, see ForgeIssueImporter::refresh().
 */
enum IssueRefreshResult
{
    case UPDATED;
    /**
     * The issue is gone from Forge, private or in another project now, and was removed from the index.
     */
    case REMOVED;
    /**
     * Neither Forge nor the index has the issue.
     */
    case NOT_FOUND;

    public function message(int $id): string
    {
        return match ($this) {
            self::UPDATED => sprintf('Issue #%d is up to date with Forge.', $id),
            self::REMOVED => sprintf('Issue #%d is gone from Forge, private or in another project, and was removed from Forger.', $id),
            self::NOT_FOUND => sprintf('Forge has no issue #%d.', $id),
        };
    }

    /**
     * The type of the flash message.
     */
    public function flashType(): string
    {
        return match ($this) {
            self::UPDATED => 'success',
            self::REMOVED => 'warning',
            self::NOT_FOUND => 'danger',
        };
    }
}
