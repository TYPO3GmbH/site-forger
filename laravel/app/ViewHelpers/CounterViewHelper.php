<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class CounterViewHelper.
 */
class CounterViewHelper extends AbstractViewHelper
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
        $this->registerArgument('increase', 'int', 'Specifies the name of an anchor');
        $this->registerArgument('output', 'int', 'Specifies the name of an anchor');
    }

    /**
     * @return int|null
     */
    public function render()
    {
        $counterId = 'CounterViewHelper_counter';
        if (array_key_exists('id', $this->arguments) && $this->arguments['id'] !== null) {
            $counterId .= ':' . $this->arguments['id'];
        }
        if (empty($GLOBALS[$counterId])) {
            $GLOBALS[$counterId] = 0;
        }
        $GLOBALS[$counterId] += (int) $this->arguments['increase'];
        if ($this->arguments['output']) {
            return $GLOBALS[$counterId];
        }
        return null;
    }
}
