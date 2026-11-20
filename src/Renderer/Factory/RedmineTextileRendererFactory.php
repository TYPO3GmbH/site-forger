<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Renderer\Factory;

use App\Renderer\RedmineTextileRenderer;
use Netcarver\Textile\Parser as TextileParser;
use TYPO3\HtmlSanitizer\Builder\CommonBuilder;

final readonly class RedmineTextileRendererFactory
{
    /**
     * @param bool $strictRedmineBlocks true: bc./pre./notextile./###. stay literal text, as in Redmine.
     *                                  false: PHP-Textile's richer syntax.
     */
    public function __invoke(bool $strictRedmineBlocks = true, bool $indentedTextIsCode = true): RedmineTextileRenderer
    {
        $textile = new TextileParser();
        // Redmine: hard_breaks=true (PHP-Textile's default line wrap) and
        // no_span_caps=true, which is symbols['caps'] === false here.
        $textile->setSymbol('caps', false);
        $sanitizer = new CommonBuilder()->build();

        return new RedmineTextileRenderer($strictRedmineBlocks, $indentedTextIsCode, $textile, $sanitizer);
    }
}
