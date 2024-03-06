<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\DTO\TrackerFilters;
use App\Enum\TrackerIdEnum;
use Netcarver\Textile\Parser;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ForgerTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }
    public function getFunctions(): array
    {
        return [
            new TwigFunction('bucketCounter', $this->bucketCounter(...), ['is_safe' => ['html']]),
            new TwigFunction('aggregationBucket', $this->aggregationBucket(...), ['is_safe' => ['html']]),
            new TwigFunction('textile', $this->textile(...), ['is_safe' => ['html']]),
            new TwigFunction('trackerCheckbox', $this->trackerCheckbox(...), ['is_safe' => ['html']]),
            new TwigFunction('forgeLink', $this->forgeLink(...)),
        ];
    }

    public function bucketCounter(string $category, array $buckets): string
    {
        $category = $this->fixWording($category);
        $activeCount = 0;
        foreach ($buckets as $bucket) {
            if (is_string($bucket['key'])) {
                $key = $this->fixWording($bucket['key'], false);
            } else {
                $key = $bucket['key'];
            }

            $filters = $this->requestStack->getMainRequest()?->get('filters');
            if (isset($filters[$category][$key]) && 'true' === $filters[$category][$key]) {
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
        if (is_string($bucket['key'])) {
            $key = $this->fixWording($bucket['key'], false);
        } else {
            $key = $bucket['key'];
        }

        $filters = $this->requestStack->getMainRequest()?->get('filters');
        if (isset($filters[$category][$key]) && 'true' === $filters[$category][$key]) {
            $checked = ' checked';
        } else {
            $checked = '';
        }

        return '<div class="form-check">'
            . '<input type="checkbox" class="form-check-input" id="' . $category . '-' . $index . '" name="filters[' . $category . '][' . $key . ']" ' . $checked . ' value="true" onchange="this.form.submit()">'
            . '<label class="form-check-label form-check-label-hascount" for="' . $category . '-' . $index . '">'
            . '<span class="form-check-label-title">' . $label . '</span> <span class="form-check-label-count">' . $docCount . '</span>'
            . '</label>'
            . '</div>';
    }

    public function textile(string $markup): string
    {
        $parser = new Parser();
        $parsedText = $parser->parse($markup);
        // Keep only whitelisted tags
        $parsedText = strip_tags($parsedText, '<h1><h2><h3><h4><h5><h6><ul><li><ol><pre><code><p>');

        // Kill off all attributes because we don't want them
        return preg_replace('/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i', '<$1$2>', $parsedText);
    }

    public function trackerCheckbox(string $trackerType, string $label): string
    {
        $checked = '';
        $trackerFilters = new TrackerFilters($this->requestStack->getMainRequest());
        if ($trackerFilters->{$trackerType}) {
            $checked = ' checked="checked';
        }

        $content = [];
        $content[] = '<div class="form-check">';
        $content[] = '<input class="form-check-input" id="checkbox-' . $trackerType . '" type="checkbox" name="trackerFilters[' . $trackerType . ']"' . $checked . ' value="true" />';
        $content[] = '<label class="form-check-label" for="checkbox-' . $trackerType . '">' . $label . '</label>';
        $content[] = '</div>';

        return implode('', $content);
    }

    private function fixWording(string|\Stringable $in, bool $lowerCase = true): string
    {
        $in = str_replace(' ', '_', (string) $in);
        if ($lowerCase) {
            $in = strtolower($in);
        }

        return $in;
    }

    public function forgeLink(int|string $year, int|string $month, string $status = 'o'): string
    {
        $redmineUrl = 'https://forge.typo3.org/projects/typo3cms-core/issues?set_filter=1';
        // @todo figure out how to work with the server-team forger here
        // $projectId = Config::get('app.redmine.projectId');
        // if ($projectId === '1862') {
        //     $redmineUrl = 'https://forge.typo3.org/projects/team-server-public/issues?set_filter=1';
        // }
        $filtersString = $this->getFiltersString($year, $month, $status);

        return $redmineUrl . $filtersString . '&f[]=' . $this->getColumnsString() . '&group_by=';
    }

    protected function getLastDayOfMonth(int|string $year, int|string $month): string
    {
        $dateString = $year . '-' . $month . '-16';

        return date('Y-m-t', strtotime($dateString));
    }

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

    protected function getFiltersString(int|string $year, int|string $month, string $statusId, ?TrackerFilters $trackerFilters = null): string
    {
        if (!$trackerFilters) {
            $trackerFilters = new TrackerFilters($this->requestStack->getMainRequest());
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
        if (!$trackerFilters->feature) {
            $filters['tracker_id']['values'][] = TrackerIdEnum::FEATURE->value;
        }
        if (!$trackerFilters->task) {
            $filters['tracker_id']['values'][] = TrackerIdEnum::TASK->value;
        }
        if (!$trackerFilters->bug) {
            $filters['tracker_id']['values'][] = TrackerIdEnum::BUG->value;
        }
        if (!$trackerFilters->epic) {
            $filters['tracker_id']['values'][] = TrackerIdEnum::EPIC->value;
        }
        if (!$trackerFilters->story) {
            $filters['tracker_id']['values'][] = TrackerIdEnum::STORY->value;
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
