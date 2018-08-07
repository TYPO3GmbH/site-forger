<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class ShortenNameViewHelper extends AbstractViewHelper
{
    /**
     * Initialize arguments.
     *
     * @throws \TYPO3Fluid\Fluid\Core\ViewHelper\Exception
     *
     * @api
     */
    public function initializeArguments()
    {
        $this->registerArgument('name', 'string', '');
    }

    /**
     * @return string
     */
    public function render() : string
    {
        $name = $this->arguments['name'];
        $nameParts = explode(' ', $name);
        $firstName = array_shift($nameParts);
        $nameParts = array_map([self::class, 'shortenName'], $nameParts);
        return $firstName . ' ' . implode(' ', $nameParts);
    }

    public static function shortenName(string $name)
    {
        return mb_substr($name, 0, 1) . '.';
    }
}
