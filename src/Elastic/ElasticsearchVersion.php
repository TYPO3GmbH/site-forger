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
     * "case_insensitive" of the wildcard query, for the search in the filters of people, see PersonSuggestion.
     */
    public const string MINIMUM = '7.10.0';

    /**
     * Elastica and the queries are written for this major version.
     */
    public const int MAJOR = 7;

    public static function result(?string $version): SystemCheckEnum
    {
        if (null === $version || '' === $version) {
            return SystemCheckEnum::UNKNOWN;
        }
        if (self::MAJOR !== (int) $version) {
            return SystemCheckEnum::PROBLEM;
        }

        return version_compare($version, self::MINIMUM, '>=') ? SystemCheckEnum::OK : SystemCheckEnum::PROBLEM;
    }
}
