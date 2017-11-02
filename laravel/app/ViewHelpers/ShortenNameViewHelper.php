<?php

namespace App\ViewHelpers;

use App\Elastic\TrackerFilters;
use Illuminate\Support\Facades\Config;
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
        $nameParts = array_map([ShortenNameViewHelper::class, 'shortenName'], $nameParts);
        return $firstName . ' ' . implode(' ', $nameParts);
    }

    static public function shortenName(string $name)
    {
        return mb_substr($name, 0, 1) . '.';
    }
}

