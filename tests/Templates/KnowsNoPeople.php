<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\People\UserDirectory;

/**
 * The pages show a person as the index "user" knows it, see UserDirectory::shown(). A test of a template with its
 * own people starts with an empty index of the users, so the index of the machine changes no name.
 */
trait KnowsNoPeople
{
    private static function knowNoPeople(): void
    {
        $cache = self::getContainer()->get('cache.app');
        $cache->delete(UserDirectory::PEOPLE_CACHE_KEY);
        $cache->get(UserDirectory::PEOPLE_CACHE_KEY, static fn (): array => ['keys' => [], 'people' => [], 'aliases' => []]);
    }
}
