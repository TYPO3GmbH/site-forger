<?php
namespace App\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class LowercaseViewHelper extends AbstractViewHelper
{

    /**
     * @var boolean
     */
    protected $escapeOutput = false;

    /**
     * Initialize arguments
     *
     * @return void
     * @api
     */
    public function initializeArguments()
    {
        $this->registerArgument('category', 'int', 'Specifies the name of an anchor');
        $this->registerArgument('index', 'int', 'Specifies the name of an anchor');
        $this->registerArgument('bucket', 'int', 'Specifies the name of an anchor');
        $this->registerArgument('docCount', 'int', 'Specifies the name of an anchor');
    }

    /**
     * @return string
     */
    public function render()
    {
        $category = '';
        if (isset($this->arguments['category'])) {
            $category = $this->arguments['category'];
        }
        $index = '';
        if (isset($this->arguments['index'])) {
            $index = $this->arguments['index'];
        }
        $bucket = '';
        if (isset($this->arguments['bucket'])) {
            $bucket = $this->arguments['bucket'];
        }
        $docCount = 0;
        if (isset($this->arguments['docCount'])) {
            $docCount = (int)$this->arguments['docCount'];
        }
        $regularBucket = $bucket;
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