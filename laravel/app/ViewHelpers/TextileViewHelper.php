<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\ViewHelpers;

use App\Service\SanitizationService;
use Netcarver\Textile\Parser;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class TextileViewHelper extends AbstractViewHelper
{
    /**
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function render() : string
    {
        $sanitizer = new SanitizationService();

        $parser = new Parser();
        $content = $this->renderChildren();
        $content = $parser->textileThis($content);
        $content = $sanitizer->sanitizeHtml($content);

        return $content;
    }
}
