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
 * What Forger needs of the version of Elasticsearch.
 */
final class ElasticsearchVersion
{
    /**
     * The oldest version the queries were written and checked for.
     */
    public const string MINIMUM = '7.10.0';

    /**
     * The major versions the queries are checked with. Elastica 7 works with both.
     */
    public const array MAJORS = [7, 8];

    public static function result(?string $version): SystemCheckEnum
    {
        if (null === $version || '' === $version) {
            return SystemCheckEnum::UNKNOWN;
        }
        if (!in_array((int) $version, self::MAJORS, true)) {
            return SystemCheckEnum::PROBLEM;
        }

        return version_compare($version, self::MINIMUM, '>=') ? SystemCheckEnum::OK : SystemCheckEnum::PROBLEM;
    }
}
