<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RedmineTextileRendererTest extends KernelTestCase
{
    #[DataProvider('redmineTicketsAreRenderedAsExpectedDataProvider')]
    public function testRedmineTicketsAreRenderedAsExpected(string $fixture): void
    {
        $input = file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.txt');
        // The expectation is trimmed to circumvent the automatically added empty line by IDEs
        $expectedOutput = trim(file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.html'));

        $renderer = self::getContainer()->get('renderer.redmineTextile');
        $output = $renderer->render($input);

        self::assertSame($expectedOutput, $output);
    }

    public static function redmineTicketsAreRenderedAsExpectedDataProvider(): \Generator
    {
        yield 'With PHP codeblock' => ['with-php-codeblock'];
        yield 'Inline script tag and formats text by indentation' => ['inline-script-and-indented-text'];
        yield 'Inline code and lists' => ['inline-code-and-lists'];
        yield 'Well formatted prose' => ['well-formatted-prose'];
    }
}
