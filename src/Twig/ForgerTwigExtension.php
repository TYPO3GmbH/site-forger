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
use App\Enum\TrackerEnum;
use App\Renderer\RedmineTextileRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

class ForgerTwigExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'renderer.redmineTextile')]
        private readonly RedmineTextileRenderer $redmineTextRenderer,
    ) {
    }

    #[AsTwigFunction(name: 'bucketCounter', isSafe: ['html'])]
    public function bucketCounter(string $category, array $buckets): string
    {
        $activeCount = $this->activeFilterCount($category, $buckets);
        if (0 === $activeCount) {
            return '';
        }

        return '<span class="badge badge-info">' . $activeCount . ' / ' . count($buckets) . '</span>';
    }

    /**
     * How many values of a filter the request selects, e.g. 2 of "Tracker" for "Bug" and "Task".
     *
     * @param list<array<string, mixed>> $buckets the values of the filter, as its aggregation has them
     */
    #[AsTwigFunction(name: 'activeFilterCount')]
    public function activeFilterCount(string $category, array $buckets): int
    {
        $category = $this->fixWording($category);
        $filters = $this->requestStack->getMainRequest()?->query->all('filters') ?? [];
        $activeCount = 0;
        foreach ($buckets as $bucket) {
            $key = is_string($bucket['key']) ? $this->fixWording($bucket['key'], false) : $bucket['key'];
            if (isset($filters[$category][$key]) && 'true' === $filters[$category][$key]) {
                ++$activeCount;
            }
        }

        return $activeCount;
    }

    #[AsTwigFunction(name: 'aggregationBucket', isSafe: ['html'])]
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

        $filters = $this->requestStack->getMainRequest()?->query->all('filters') ?? [];
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

    #[AsTwigFilter(name: 'forgeMarkdown', isSafe: ['html'])]
    public function forgeMarkdown(string $markdown): string
    {
        return $this->redmineTextRenderer->render($markdown);
    }

    /**
     * The beginning of a description as plain text, for a list: without markup and code, the whitespace in one line,
     * cut at a word with an ellipsis. Empty when there is no text.
     */
    #[AsTwigFilter(name: 'forgeTeaser')]
    public function forgeTeaser(?string $markdown, int $length = 240): string
    {
        if (null === $markdown || '' === trim($markdown)) {
            return '';
        }
        $html = $this->redmineTextRenderer->render($markdown);
        // A block of code says little out of its context
        $html = (string) preg_replace('#<pre\b[^>]*>.*?</pre>#is', ' ', $html);
        // Blocks stay apart as words
        $html = (string) preg_replace('#<(br|/p|/h[1-6]|/li|/div|/tr|/td|/th|/blockquote)\b[^>]*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if (false !== $space && $space > $length / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n.,;:-") . '…';
    }

    #[AsTwigFunction(name: 'trackerCheckbox', isSafe: ['html'])]
    public function trackerCheckbox(string $trackerType, string $label): string
    {
        $tracker = null;
        foreach (TrackerEnum::filterable() as $filterable) {
            if ($filterable->severity() === $trackerType) {
                $tracker = $filterable;
            }
        }
        if (null === $tracker) {
            throw new \InvalidArgumentException(sprintf('The tracker "%s" cannot be filtered.', $trackerType));
        }
        $checked = '';
        $trackerFilters = new TrackerFilters($this->requestStack->getMainRequest());
        if ($trackerFilters->isExcluded($tracker)) {
            $checked = ' checked="checked"';
        }

        $content = [];
        $content[] = '<div class="form-check">';
        $content[] = '<input class="form-check-input" id="checkbox-' . $trackerType . '" type="checkbox" name="trackerFilters[' . $trackerType . ']"' . $checked . ' value="true" />';
        $content[] = '<label class="form-check-label" for="checkbox-' . $trackerType . '">' . $label . '</label>';
        $content[] = '</div>';

        return implode('', $content);
    }

    /**
     * A value as the search expects it in "filters[<name>][<value>]", like the filter checkboxes write it.
     */
    #[AsTwigFilter(name: 'filterKey')]
    public function filterKey(string|\Stringable $value): string
    {
        return $this->fixWording($value, false);
    }

    private function fixWording(string|\Stringable $in, bool $lowerCase = true): string
    {
        $in = str_replace(' ', '_', (string) $in);
        if ($lowerCase) {
            $in = strtolower($in);
        }

        return $in;
    }

    #[AsTwigFunction(name: 'forgeLink')]
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
        foreach ($trackerFilters->included() as $tracker) {
            $filters['tracker_id']['values'][] = $tracker->value;
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
