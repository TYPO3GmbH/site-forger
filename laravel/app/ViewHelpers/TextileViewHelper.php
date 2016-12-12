<?php
namespace App\ViewHelpers;

use App\Service\SanitizationService;
use Netcarver\Textile\Parser;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class TextileViewHelper extends AbstractViewHelper
{

    /**
     * @return string
     * @throws \InvalidArgumentException
     */
    public function render()
    {
        $sanitizer = new SanitizationService();

        $parser = new Parser();
        $content = $this->renderChildren();
        $content = $parser->textileThis($content);
        $content = $sanitizer->sanitizeHtml($content);

        return $content;
    }
}