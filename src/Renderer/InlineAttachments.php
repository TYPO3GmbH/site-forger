<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Renderer;

use App\Document\IssueAttachment;

/**
 * Turns the images of a text that name a file of its issue into links to the file.
 *
 * Textile writes "!screenshot.png!" for an image the issue has as a file, and Redmine shows the file there, see
 * parse_inline_attachments. The renderer makes that a relative <img src="screenshot.png">, which does not exist on
 * Forger. Forger cannot show the image either: Forge answers an image on another site with the page of its bot
 * check, also to a browser that passed the check. A link opens the file on Forge.
 */
final class InlineAttachments
{
    /**
     * @param list<IssueAttachment> $files
     */
    public static function resolve(string $html, array $files): string
    {
        $urls = [];
        // Redmine compares the names without case, a later file of the same name wins
        foreach ($files as $file) {
            $urls[mb_strtolower($file->filename)] = $file->url;
        }

        return preg_replace_callback(
            '~<img\b[^>]*\bsrc="([^"/:]+)"[^>]*>~i',
            static function (array $match) use ($urls): string {
                $name = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
                $url = $urls[mb_strtolower($name)] ?? null;
                // A file Forger does not know, as of an older import: its name instead of a broken image
                if (null === $url) {
                    return htmlspecialchars($name);
                }

                return sprintf(
                    '<a href="%s" target="_blank" rel="noopener">%s</a>',
                    htmlspecialchars($url, ENT_QUOTES),
                    htmlspecialchars($name),
                );
            },
            $html,
        ) ?? $html;
    }
}
