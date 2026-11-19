<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * Gerrit change states, as stored in "reviewstatus" of the review index.
 */
enum ReviewStatusEnum: string
{
    case NEW = 'NEW';
    case MERGED = 'MERGED';
    case ABANDONED = 'ABANDONED';

    /**
     * @return list<self>
     */
    public static function closed(): array
    {
        return [self::MERGED, self::ABANDONED];
    }

    /**
     * The values of the closed states, ready to be used in an Elasticsearch "terms" query.
     *
     * @return list<string>
     */
    public static function closedValues(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::closed());
    }

    public function isClosed(): bool
    {
        return in_array($this, self::closed(), true);
    }
}
