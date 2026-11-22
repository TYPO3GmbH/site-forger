import { html, type TemplateResult } from 'lit';

/**
 * The median of the values, null without one. A bar chart draws it as a line across, see medianLine(): the bars far
 * above it stand out, without cutting them off.
 */
export function medianOf(values: (number | null)[]): number | null {
    const sorted = values
        .filter((value): value is number => value !== null)
        .sort((a, b) => a - b);
    if (sorted.length === 0) {
        return null;
    }
    const middle = Math.floor(sorted.length / 2);

    return sorted.length % 2 === 1
        ? sorted[middle] ?? null
        : ((sorted[middle - 1] ?? 0) + (sorted[middle] ?? 0)) / 2;
}

/**
 * The median as a dashed line across the bars, at its height on the scale up to "top". Put it into ".stream-bars".
 */
export function medianLine(median: number | null, top: number): TemplateResult | string {
    if (median === null || top <= 0) {
        return '';
    }

    return html`<span class="stream-median" style="--median: ${Math.min(1, median / top).toFixed(4)}"></span>`;
}

/**
 * The key of the median line, above the bars opposite the scale: "Median 2.4 days".
 */
export function medianKey(text: string): TemplateResult {
    return html`<span class="stream-median-key">Median ${text}</span>`;
}
