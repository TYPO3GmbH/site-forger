<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Twig;

use App\Twig\PathTwigExtension;
use PHPUnit\Framework\TestCase;

final class PathTwigExtensionTest extends TestCase
{
    public function testFoldersComeBeforeFilesOnEveryLevel(): void
    {
        self::assertSame(
            [
                'Build/Sources/TypeScript/form-engine.ts',
                'Build/package.json',
                'typo3/sysext/backend/Classes/Page.php',
                'typo3/sysext/core/Classes/Cache/Backend.php',
                'typo3/sysext/core/Classes/Cache.php',
                'typo3/sysext/core/ext_localconf.php',
                'composer.json',
                'README.md',
            ],
            PathTwigExtension::sortPaths([
                'README.md',
                'typo3/sysext/core/ext_localconf.php',
                'Build/package.json',
                'typo3/sysext/core/Classes/Cache.php',
                'composer.json',
                'typo3/sysext/core/Classes/Cache/Backend.php',
                'Build/Sources/TypeScript/form-engine.ts',
                'typo3/sysext/backend/Classes/Page.php',
            ])
        );
    }

    public function testNumbersCountAsNumbers(): void
    {
        self::assertSame(['Migrations/Version2.php', 'Migrations/Version10.php'], PathTwigExtension::sortPaths(['Migrations/Version10.php', 'Migrations/Version2.php']));
    }
}
