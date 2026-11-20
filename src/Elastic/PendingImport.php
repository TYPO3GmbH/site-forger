<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

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
                'label' => 'Issues of an older import',
                'description' => 'Written before the import took the data of its version. Each "Update Forge issues" loads some of them again, the ones changed last first.',
                'index' => 'issue',
                'version' => ImportSchema::ISSUE,
                'query' => ImportSchema::outdated('issue'),
            ],
            [
                'label' => 'Changes of an older import',
                'description' => 'Written before the import took the data of its version. Each "Update Gerrit changes" loads some of them again, the ones changed last first.',
                'index' => 'review',
                'version' => ImportSchema::REVIEW,
                'query' => ImportSchema::outdated('review'),
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
