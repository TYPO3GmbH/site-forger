<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\ViewHelpers\Form;

use App\Elastic\TrackerFilters;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class CheckboxViewHelper extends AbstractViewHelper
{
    public function __construct()
    {
        $this->escapeOutput = false;
    }

    /**
     * Initialize arguments.
     *
     * @api
     * @throws \TYPO3Fluid\Fluid\Core\ViewHelper\Exception
     */
    public function initializeArguments()
    {
        $this->registerArgument('property', 'string', 'Specifies the name of an anchor');
        $this->registerArgument('value', 'string', 'Specifies the name of an anchor');
    }

    /**
     * @return string
     */
    public function render() : string
    {
        $property = $this->arguments['property'];
        $checked = '';
        $trackerFilters = new TrackerFilters();
        if ($trackerFilters->$property === true) {
            $checked = ' checked="checked';
        }
        $content = '<input type="checkbox" name="trackerFilters[' . $property . ']"' . $checked . ' value="true" />';

        return $content;
    }
}
