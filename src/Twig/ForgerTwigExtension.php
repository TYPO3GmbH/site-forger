<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\Elastic\GlobalSearch;
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
    /**
     * The filters the link to a tab of the search takes along from the page, see GlobalSearch::tabFilters().
     *
     * @return array<string, array<string, string>>
     */
    #[AsTwigFunction(name: 'search_tab_filters')]
    public function searchTabFilters(string $tab): array
    {
        return GlobalSearch::tabFilters($tab, $this->requestStack->getMainRequest()?->query->all('filters') ?? []);
    }

    /**
     * Whether any filter of the list is set: a value ticked, a path or days typed in.
     */
    #[AsTwigFunction(name: 'has_active_filters')]
    public function hasActiveFilters(mixed $filters): bool
    {
        foreach (is_array($filters) ? $filters : [] as $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_scalar($value) && '' !== trim((string) $value) && 'false' !== $value) {
                    return true;
                }
            }
        }

        return false;
    }

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

    /**
     * A value of a filter as a checkbox, with its count. The value is escaped; $labelHtml shows instead, like a
     * person with the avatar, see templates/_partials/aggregations.html.twig.
     *
     * @param array<string, mixed> $bucket
     */
    #[AsTwigFunction(name: 'aggregationBucket', isSafe: ['html'])]
    public function aggregationBucket(string $category, string $index, array $bucket, string|\Stringable|null $labelHtml = null): string
    {
        $label = null === $labelHtml ? htmlspecialchars((string) ($bucket['key_as_string'] ?? $bucket['key']), ENT_QUOTES) : (string) $labelHtml;
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
            . '<input type="checkbox" class="form-check-input" id="' . htmlspecialchars($category . '-' . $index, ENT_QUOTES) . '" name="filters[' . htmlspecialchars($category, ENT_QUOTES) . '][' . htmlspecialchars((string) $key, ENT_QUOTES) . ']" ' . $checked . ' value="true" onchange="this.form.submit()">'
            . '<label class="form-check-label form-check-label-hascount" for="' . htmlspecialchars($category . '-' . $index, ENT_QUOTES) . '">'
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
}
