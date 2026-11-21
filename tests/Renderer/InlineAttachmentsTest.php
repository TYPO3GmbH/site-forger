<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Renderer;

use App\Document\IssueAttachment;
use App\Renderer\InlineAttachments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineAttachmentsTest extends TestCase
{
    private const string URL = 'https://forge.typo3.org/attachments/download/37897/db_field_value.jpg';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function images(): iterable
    {
        $link = '<a href="' . self::URL . '" target="_blank" rel="noopener">';
        yield 'the name of a file' => ['<img alt src="db_field_value.jpg">', $link . 'db_field_value.jpg</a>'];
        yield 'the name in other case' => ['<img alt src="DB_Field_Value.JPG">', $link . 'DB_Field_Value.JPG</a>'];
        yield 'a name the issue has no file of' => ['<p><img alt src="other.png"></p>', '<p>other.png</p>'];
        yield 'an address' => [
            '<img alt src="https://example.org/db_field_value.jpg">',
            '<img alt src="https://example.org/db_field_value.jpg">',
        ];
        yield 'a path' => ['<img alt src="/files/db_field_value.jpg">', '<img alt src="/files/db_field_value.jpg">'];
        yield 'a link, not an image' => [
            '<a href="db_field_value.jpg">file</a>',
            '<a href="db_field_value.jpg">file</a>',
        ];
    }

    #[DataProvider('images')]
    public function testTheImagesOfTheIssueLeadToItsFiles(string $html, string $expected): void
    {
        $files = [new IssueAttachment('db_field_value.jpg', self::URL)];

        self::assertSame($expected, InlineAttachments::resolve($html, $files));
    }

    public function testALaterFileOfTheSameNameWins(): void
    {
        $files = [
            new IssueAttachment('shot.png', 'https://forge.typo3.org/attachments/download/1/shot.png'),
            new IssueAttachment('shot.png', 'https://forge.typo3.org/attachments/download/2/shot.png'),
        ];

        self::assertSame(
            '<a href="https://forge.typo3.org/attachments/download/2/shot.png" target="_blank" rel="noopener">'
                . 'shot.png</a>',
            InlineAttachments::resolve('<img src="shot.png">', $files),
        );
    }
}
