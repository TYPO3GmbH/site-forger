<?php

namespace App\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class AggregationBucketViewHelper extends AbstractViewHelper
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
        $this->registerArgument('category', 'int', 'Specifies the name of an anchor', false, '');
        $this->registerArgument('index', 'int', 'Specifies the name of an anchor', false, '');
        $this->registerArgument('bucket', 'int', 'Specifies the name of an anchor', false, '');
        $this->registerArgument('docCount', 'int', 'Specifies the name of an anchor', false, 0);
        $this->registerArgument('label', 'char', 'Specifies the label of an anchor', false, 0);
    }

    /**
     * @return string
     */
    public function render() : string
    {
        $category = $this->arguments['category'];
        $index = $this->arguments['index'];
        $bucket = $this->arguments['bucket'];
        $docCount = (int) $this->arguments['docCount'];
        $regularBucket = $this->arguments['label'];
        $category = $this->fixWording($category);
        $bucket = $this->fixWording($bucket, false);

        // check if checkbox has been set
        if (isset($_GET['filters'][$category][$bucket]) && $_GET['filters'][$category][$bucket] === 'true') {
            $checked = ' checked';
            $labelStyle = ' style="font-weight:bold; color:#008cba;"';
        } else {
            $checked = '';
            $labelStyle = ' style="font-weight: normal;"';
        }

        $content = '
            <label' . $labelStyle . ' for="' . $category . '-' . $index . '">'
                . '<input type="checkbox" id="' . $category . '-' . $index . '" name="filters[' . $category . '][' . $bucket . ']"' . $checked . ' value="true" />' . $regularBucket . ' (' . $docCount . ')'
            . '</label>';

        return $content;
    }

    /**
     * @param string $in
     * @param bool   $lowercase
     *
     * @return mixed|string
     */
    private function fixWording($in, $lowercase = true)
    {
        $in = str_replace(' ', '_', $in);
        if ($lowercase) {
            $in = strtolower($in);
        }

        return $in;
    }
}
