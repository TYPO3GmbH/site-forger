<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Elastic\Indices;
use App\Enum\SystemCheckEnum;

/**
 * The documents an older version of the import wrote, for the system page: they show without the data the import
 * takes since, until the deltas load them again, see ImportSchema. Nothing has to be started for it.
 */
final class PendingImport
{
    /**
     * @return list<array{label: string, description: string, index: 'issue'|'review', version: int, query: array<string, mixed>}>
     */
    public static function checks(): array
    {
        return [
            [
                'label' => 'Outdated issues',
                'description' => 'They lack the newer fields. Each run of "Update Forge issues" updates some of them,'
                    . ' the most recently changed first.',
                'index' => Indices::ISSUE,
                'version' => ImportSchema::ISSUE,
                'query' => ImportSchema::outdated(Indices::ISSUE),
            ],
            [
                'label' => 'Outdated changes',
                'description' => 'They lack the newer fields. Each run of "Update Gerrit changes" updates some of them,'
                    . ' the most recently changed first.',
                'index' => Indices::REVIEW,
                'version' => ImportSchema::REVIEW,
                'query' => ImportSchema::outdated(Indices::REVIEW),
            ],
        ];
    }

    /**
     * @param int $matches the documents of an older import
     */
    public static function result(int $matches): SystemCheckEnum
    {
        return 0 === $matches ? SystemCheckEnum::OK : SystemCheckEnum::ATTENTION;
    }
}
