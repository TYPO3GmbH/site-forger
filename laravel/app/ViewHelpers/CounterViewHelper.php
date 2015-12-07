<?php
namespace App\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class CounterViewHelper
 *
 * @package WMDB\Forger\ViewHelpers
 */
class CounterViewHelper extends AbstractViewHelper
{

    /**
     * Initialize arguments
     *
     * @return void
     * @api
     */
    public function initializeArguments()
    {
        $this->registerArgument('increase', 'int', 'Specifies the name of an anchor');
        $this->registerArgument('output', 'int', 'Specifies the name of an anchor');
    }

    /**
     *
     * @return int
     */
    public function render()
    {
        $counterId = 'CounterViewHelper_counter';
        if ($this->arguments['id'] !== null) {
            $counterId .= ':' . $this->arguments['id'];
        }
        if (empty($GLOBALS[$counterId])) {
            $GLOBALS[$counterId] = 0;
        }
        $GLOBALS[$counterId] = ($GLOBALS[$counterId] + (int)$this->arguments['increase']);
        if ($this->arguments['output']) {
            return $GLOBALS[$counterId];
        }
    }
}
