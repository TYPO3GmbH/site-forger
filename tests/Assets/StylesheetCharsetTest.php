<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The stylesheet is served without a charset and its "@charset" does not stand first, so a browser may read it as
 * Windows-1252: a "›" in a string shows as "â€º". Strings use CSS escapes ("\203A") instead.
 */
final class StylesheetCharsetTest extends TestCase
{
    public function testStringsOfTheStylesheetsAreAscii(): void
    {
        $found = [];
        foreach (new Finder()->files()->in(dirname(__DIR__, 2) . '/assets/css')->name('*.scss') as $file) {
            foreach (explode("\n", $file->getContents()) as $number => $line) {
                // Comments may say what an escape stands for
                $code = (string) preg_replace('~//.*$|/\*.*?\*/~', '', $line);
                if (preg_match('~(["\'])[^"\']*[^\x00-\x7F][^"\']*\1~', $code)) {
                    $found[] = $file->getRelativePathname() . ':' . ($number + 1) . ': ' . trim($line);
                }
            }
        }

        self::assertSame([], $found, 'Use a CSS escape for these characters');
    }
}
