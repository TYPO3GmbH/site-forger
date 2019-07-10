<?php


namespace App\Twig;


use Netcarver\Textile\Parser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ForgerTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('idCounter', [$this, 'idCounter']),
            new TwigFunction('bucketCounter', [$this, 'bucketCounter']),
            new TwigFunction('aggregationBucket', [$this, 'aggregationBucket']),
            new TwigFunction('textile', [$this, 'textile']),
        ];
    }

    /**
     * Used to count up accordion IDs in aggregations
     * @param int $increase
     * @param bool $output
     * @return mixed|null
     */
    public function idCounter(int $increase = 1, bool $output = false)
    {
        $counterId = 'CounterViewHelper_counter';
//        if (array_key_exists('id', $this->arguments) && $this->arguments['id'] !== null) {
//            $counterId .= ':' . $this->arguments['id'];
//        }
        if (empty($GLOBALS[$counterId])) {
            $GLOBALS[$counterId] = 0;
        }
        $GLOBALS[$counterId] += $increase;
        if ($output) {
            return $GLOBALS[$counterId];
        }
        return null;
    }

    public function bucketCounter(string $category, array $buckets): string
    {
        $category = $this->fixWording($category);
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

        return '<span class="badge badge-info float-right">' . $activeCount . ' / ' . count($buckets) . '</span>';
    }

    public function aggregationBucket(string $category, string $index, array $bucket): string
    {
        $label = $bucket['key_as_string'] ?? $bucket['key'];
        $docCount = $bucket['doc_count'];
        $category = $this->fixWording($category);
//        $bucket = $this->fixWording($bucket, false);

        // check if checkbox has been set
        if (isset($_GET['filters'][$category][$bucket['key']]) && $_GET['filters'][$category][$bucket['key']] === 'true') {
            $checked = ' checked';
            $labelStyle = ' style="font-weight:bold; color:#008cba;"';
        } else {
            $checked = '';
            $labelStyle = ' style="font-weight: normal;"';
        }

        $content = '
            <label class="form-check-label"' . $labelStyle . ' for="' . $category . '-' . $index . '">'
            . '<input class="form-check-input" type="checkbox" id="' . $category . '-' . $index . '" name="filters[' . $category . '][' . $bucket['key'] . ']"' . $checked . ' value="true" />' . $label . ' (' . $docCount . ')'
            . '</label>';

        return $content;
    }

    public function textile(string $markup)
    {
        $parser = new Parser();
        $parsedText = $parser->parse($markup);
        /*
         * Keep only whitelisted tags
         */
        $parsedText = strip_tags($parsedText, '<h1><h2><h3><h4><h5><h6><ul><li><ol><pre><code><p>');

        /*
         * Kill off all attributes because we don't want them
         */
        $parsedText = preg_replace('/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i', '<$1$2>', $parsedText);
        return $parsedText;
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