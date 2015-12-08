<?php
namespace App\ViewHelpers\Form;

use App\Elastic\TrackerFilters;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class CheckboxViewHelper extends AbstractViewHelper
{

    /**
     * Initialize arguments
     *
     * @return void
     * @api
     */
    public function initializeArguments()
    {
        $this->registerArgument('property', 'string', 'Specifies the name of an anchor');
        $this->registerArgument('value', 'string', 'Specifies the name of an anchor');
    }

    /**
     * @return string
     */
    public function render()
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
