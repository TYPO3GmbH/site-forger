<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\Entity\TrackerFilters;
use Netcarver\Textile\Parser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ForgerTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('bucketCounter', [$this, 'bucketCounter'], ['is_safe' => ['html']]),
            new TwigFunction('aggregationBucket', [$this, 'aggregationBucket'], ['is_safe' => ['html']]),
            new TwigFunction('textile', [$this, 'textile'], ['is_safe' => ['html']]),
            new TwigFunction('trackerCheckbox', [$this, 'trackerCheckbox'], ['is_safe' => ['html']]),
            new TwigFunction('forgeLink', [$this, 'forgeLink']),
        ];
    }

    public function bucketCounter(string $category, array $buckets): string
    {
        $category = $this->fixWording($category);
        $activeCount = 0;
        foreach ($buckets as $bucket) {
            $key = $this->fixWording($bucket['key'], false);
            if (isset($_GET['filters'][$category][$key]) && 'true' === $_GET['filters'][$category][$key]) {
                ++$activeCount;
            }
        }
        if (0 === $activeCount) {
            return '';
        }

        return '<span class="badge badge-info">' . $activeCount . ' / ' . count($buckets) . '</span>';
    }

    public function aggregationBucket(string $category, string $index, array $bucket): string
    {
        $label = $bucket['key_as_string'] ?? $bucket['key'];
        $docCount = $bucket['doc_count'];
        $category = $this->fixWording($category);
        $key = $this->fixWording($bucket['key'], false);

        // check if checkbox has been set
        if (isset($_GET['filters'][$category][$key]) && 'true' === $_GET['filters'][$category][$key]) {
            $checked = ' checked';
        } else {
            $checked = '';
        }
        $content = '<div class="form-check">'
            . '<input type="checkbox" class="form-check-input" id="' . $category . '-' . $index . '" name="filters[' . $category . '][' . $key . ']" ' . $checked . ' value="true" onchange="this.form.submit()">'
            . '<label class="form-check-label form-check-label-hascount" for="' . $category . '-' . $index . '">'
            . '<span class="form-check-label-title">' . $label . '</span> <span class="form-check-label-count">' . $docCount . '</span>'
            . '</label>'
            . '</div>';

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

    public function trackerCheckbox(string $trackerType, string $label): string
    {
        $checked = '';
        $trackerFilters = new TrackerFilters();
        if ($trackerFilters->$trackerType === true) {
            $checked = ' checked="checked';
        }

        $content = [];
        $content[] = '<div class="custom-control custom-checkbox">';
        $content[] = '<input id="checkbox-' . $trackerType . '" type="checkbox" class="custom-control-input" name="trackerFilters[' . $trackerType . ']"' . $checked . ' value="true" />';
        $content[] = '<label for="checkbox-' . $trackerType . '" class="custom-control-label">' . $label . '</label>';
        $content[] = '</div>';

        return implode('', $content);
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

    /**
     * @param string $year
     * @param string $month
     * @param string $status
     *
     * @return string
     */
    public function forgeLink(string $year, string $month, $status = 'o'): string
    {
        $redmineUrl = 'https://forge.typo3.org/projects/typo3cms-core/issues?set_filter=1';
        // @todo figure out how to work with the server-team forger here
        // $projectId = Config::get('app.redmine.projectId');
        // if ($projectId === '1862') {
        //     $redmineUrl = 'https://forge.typo3.org/projects/team-server-public/issues?set_filter=1';
        // }
        $filtersString = $this->getFiltersString($year, $month, $status);
        $content = $redmineUrl . $filtersString . '&f[]=' . $this->getColumnsString() . '&group_by=';

        return $content;
    }

    /**
     * @param string $year
     * @param string $month
     *
     * @return string
     */
    protected function getLastDayOfMonth($year = '', $month = ''): string
    {
        $dateString = $year . '-' . $month . '-16';

        return date('Y-m-t', strtotime($dateString));
    }

    /**
     * @return string
     */
    protected function getColumnsString(): string
    {
        $string = '';
        $columns = [
            'tracker',
            'status',
            'priority',
            'subject',
            'assigned_to',
            'category',
            'fixed_version',
        ];
        foreach ($columns as $columnName) {
            $string .= '&c[]=' . $columnName;
        }

        return $string;
    }

    /**
     * @param string         $year
     * @param string         $month
     * @param string         $statusId
     * @param TrackerFilters $trackerFilters
     *
     * @return string
     */
    protected function getFiltersString($year, $month, $statusId, TrackerFilters $trackerFilters = null): string
    {
        if (!$trackerFilters) {
            $trackerFilters = new TrackerFilters();
        }
        $end = $this->getLastDayOfMonth($year, $month);
        $string = '';
        $filters = [
            'status_id' => [
                'type' => '=',
                'values' => [
                    $statusId,
                ],
            ],
            'tracker_id' => [
                'type' => '=',
                'values' => [],
            ],
            'updated_on' => [
                'type' => '><',
                'values' => [
                    $year . '-' . $month . '-01',
                    $end,
                ],
            ],
        ];

        /*
         * Tracker Filters
         */
        if (!$trackerFilters->isFeature()) {
            $filters['tracker_id']['values'][] = '2';
        }
        if (!$trackerFilters->isTask()) {
            $filters['tracker_id']['values'][] = '4';
        }
        if (!$trackerFilters->isBug()) {
            $filters['tracker_id']['values'][] = '1';
        }
        if (!$trackerFilters->isEpic()) {
            $filters['tracker_id']['values'][] = '10';
        }
        if (!$trackerFilters->isStory()) {
            $filters['tracker_id']['values'][] = '6';
        }
        if ('o' === $statusId) {
            $filters['status_id']['type'] = 'o';
            $filters['status_id']['values'] = [];
        }
        foreach ($filters as $fieldName => $config) {
            $string .= '&f[]=' . $fieldName;
            $string .= '&op[' . $fieldName . ']=' . $config['type'];
            foreach ($config['values'] as $value) {
                $string .= '&v[' . $fieldName . '][]=' . $value;
            }
        }

        return $string;
    }
}
