<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts;

/**
 * Datasets and options for the Chart.js 3 of the template bundle, which draws every "canvas.js-chart"
 * with the options of its "data-options" attribute.
 *
 * Colors by the job they do, validated for color vision deficiencies on the white card surface:
 * Gerrit changes blue, Forge issues orange, closed aqua. Comparisons of years are one blue hue,
 * the current year darkest. Aqua is below 3:1 against white, the charts therefore keep their legend
 * and a table of their data.
 */
final class ChartStyle
{
    public const string CHANGES = '#2a78d6';
    public const string ISSUES = '#eb6834';
    public const string CLOSED = '#1baf7a';

    /**
     * Ordinal steps of the blue ramp, the current year first.
     */
    public const array YEARS = ['#184f95', '#3987e5', '#86b6ef'];

    private const string INK_SECONDARY = '#52514e';
    private const string INK_MUTED = '#898781';
    private const string GRID = '#e1e0d9';
    private const string TOOLTIP = '#0b0b0b';

    /**
     * A line, without points until hovered. $area fills it lightly down to the axis.
     *
     * @param list<int|null> $data
     *
     * @return array<string, mixed>
     */
    public static function line(string $label, array $data, string $color, bool $area = false): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'borderColor' => $color,
            'backgroundColor' => $area ? self::transparent($color, .12) : $color,
            'pointBackgroundColor' => $color,
            'pointBorderColor' => '#ffffff',
            'fill' => $area ? 'origin' : false,
        ];
    }

    /**
     * Bars with rounded ends at the value. The gap between neighbours comes from their width:
     * a border would be drawn on every side and leave thin bars.
     *
     * @param list<int> $data
     *
     * @return array<string, mixed>
     */
    public static function bar(string $label, array $data, string $color): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $color,
            'hoverBackgroundColor' => $color,
            'borderWidth' => 0,
            'borderRadius' => 4,
            'borderSkipped' => 'start',
            'categoryPercentage' => .8,
            'barPercentage' => .9,
            'maxBarThickness' => 40,
        ];
    }

    /**
     * The options of a line or bar chart. A single series needs no legend, its title names it.
     * $horizontal lays bars along the value axis, for categories with long names.
     *
     * @return array<string, mixed>
     */
    public static function options(bool $legend = false, bool $horizontal = false): array
    {
        $font = ['family' => 'inherit', 'size' => 12];

        $options = [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'layout' => ['padding' => ['top' => 4]],
            'plugins' => [
                'legend' => [
                    'display' => $legend,
                    'position' => 'top',
                    'align' => 'start',
                    'labels' => [
                        'color' => self::INK_SECONDARY,
                        'font' => $font,
                        'usePointStyle' => true,
                        'pointStyle' => 'rectRounded',
                        'boxWidth' => 10,
                        'boxHeight' => 10,
                        'padding' => 16,
                    ],
                ],
                'tooltip' => [
                    'backgroundColor' => self::TOOLTIP,
                    'padding' => 10,
                    'cornerRadius' => 6,
                    'boxPadding' => 4,
                    'usePointStyle' => true,
                    'titleFont' => ['weight' => '600'],
                ],
            ],
            'elements' => [
                'line' => ['borderWidth' => 2, 'tension' => .25],
                'point' => ['radius' => 0, 'hoverRadius' => 5, 'hitRadius' => 12, 'hoverBorderWidth' => 2],
            ],
        ];
        $categories = [
            'grid' => ['display' => false, 'drawBorder' => false],
            'ticks' => ['color' => self::INK_MUTED, 'font' => $font, 'maxRotation' => 0, 'autoSkipPadding' => 16],
        ];
        $values = [
            'beginAtZero' => true,
            'grid' => ['color' => self::GRID, 'drawBorder' => false],
            'ticks' => ['color' => self::INK_MUTED, 'font' => $font, 'precision' => 0, 'maxTicksLimit' => 5, 'padding' => 8],
        ];
        if ($horizontal) {
            $options['indexAxis'] = 'y';
            $options['interaction'] = ['mode' => 'nearest', 'axis' => 'y', 'intersect' => false];
            $categories['ticks']['color'] = self::INK_SECONDARY;
            $categories['ticks']['font'] = ['family' => 'inherit', 'size' => 13];
        }
        $options['scales'] = $horizontal ? ['x' => $values, 'y' => $categories] : ['x' => $categories, 'y' => $values];

        return $options;
    }

    private static function transparent(string $hex, float $alpha): string
    {
        [$red, $green, $blue] = array_map(hexdec(...), str_split(ltrim($hex, '#'), 2));

        return sprintf('rgba(%d,%d,%d,%s)', $red, $green, $blue, $alpha);
    }
}
