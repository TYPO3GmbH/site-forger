<?php

namespace App\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class BucketCounterViewHelper.
 */
class BucketCounterViewHelper extends AbstractViewHelper
{
    public function __construct()
    {
        $this->escapeOutput = false;
    }

    /**
     * Initialize arguments.
     *
     * @throws \TYPO3Fluid\Fluid\Core\ViewHelper\Exception
     *
     * @api
     */
    public function initializeArguments()
    {
        $this->registerArgument('buckets', 'string', 'Specifies the name of an anchor');
        $this->registerArgument('category', 'string', 'Specifies the name of an anchor');
    }

    /**
     * @return string
     */
    public function render() : string
    {
        $buckets = $this->arguments['buckets'];
        $category = $this->fixWording($this->arguments['category']);
        $activeCount = 0;
        foreach ($buckets as $bucket) {
            $key = $this->fixWording($bucket['key'], false);
            if (isset($_GET['filters'][$category][$key]) && $_GET['filters'][$category][$key] === 'true') {
                ++$activeCount;
            }
        }
        if ($activeCount === 0) {
            return '';
        }

        return '<span class="label label-default pull-right">' . $activeCount . ' / ' . count($buckets) . '</span>';
    }

    /**
     * @param      $in
     * @param bool $lowerCase
     *
     * @return mixed|string
     */
    private function fixWording($in, $lowerCase = true)
    {
        $in = str_replace(' ', '_', $in);
        if ($lowerCase) {
            $in = strtolower($in);
        }

        return $in;
    }
}
