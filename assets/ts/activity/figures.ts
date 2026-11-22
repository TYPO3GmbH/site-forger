import { html, nothing, svg, type TemplateResult } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { icon, sources } from './definitions';
import { bucketLabel, bucketTick, formatNumber } from './format';
import { handledByPage, LightElement } from './light-element';
import { applyChange, pageParameters, withQuery, type ActivityChange, type ActivityState } from './state';
import { withTooltips } from './tooltip';
import type {
    ActivityCrew,
    ActivityDuration,
    ActivityFigure,
    ActivityStatistics,
    ActivityTrend,
    ActivityWaitBand,
    Better,
    Source,
} from './types';

withTooltips();

/**
 * The part of the key figures an element renders, each in its place of the dashboard: the open issues and changes
 * as charts on top, the counts as tiles, the waits and the changes that wait now as charts, the crews of the review
 * as one card.
 */
export type FigureGroup = 'open' | 'counts' | 'waits' | 'crew';

/**
 * The ticks below the line of the open issues and changes: the chart is half the board wide.
 */
const OPEN_TICKS = 6;

/**
 * The key figures of the statistics: what the people got done, each with its sparkline over the window and the
 * change to the time before, as long as the window. A figure opens the stream of its events. The line takes the
 * color of the channel, "--stream-<key>" of assets/css/module/_stream.scss. The element has no box of its own: its
 * tiles and cards are widgets of the dashboard grid around it, see ".stream-dashboard".
 */
@customElement('forger-activity-figures')
export class ActivityFigures extends LightElement {
    @property({ attribute: false }) statistics!: ActivityStatistics;
    @property({ attribute: false }) state!: ActivityState;
    @property({ attribute: 'stream-url' }) streamUrl = '';
    @property({ attribute: 'group' }) group: FigureGroup = 'counts';

    protected override render(): TemplateResult | typeof nothing {
        if (this.group === 'waits') {
            return html`
                ${this.statistics.durations.map(duration => this.duration(duration))}
                ${this.reviewQueue()}
            `;
        }
        if (this.group === 'crew') {
            return this.crews();
        }
        if (this.group === 'open') {
            return html`
                ${this.open('Open issues', this.statistics.openIssues, 'forge')}
                ${this.open('Open changes', this.statistics.openChanges, 'gerrit')}
            `;
        }

        return this.counts();
    }

    /**
     * The tiles: the people, then per channel what got done. They have a grid of their own with five columns, see
     * ".stream-tiles": the people and the four figures of Forge fill a row, the five figures of Gerrit the next.
     */
    private counts(): TemplateResult {
        const statistics = this.statistics;
        const ofSource = (value: Source): TemplateResult[] => statistics.figures
            .filter(figure => figure.source === value)
            .map(figure => this.figure(figure));

        return html`
            <div class="stream-tiles stream-widget is-full">
                ${this.state.own === null ? this.people() : this.activeDays()}
                ${ofSource('forge')}
                ${ofSource('gerrit')}
            </div>
        `;
    }

    /**
     * The people active in the window.
     */
    private people(): TemplateResult {
        const trend = this.statistics.people;
        const before = `${formatNumber(trend.previous)} ${comparedWith(this.statistics)}`;

        return html`
            <div class="stream-figure" title="The people with activity">
                <span class="stream-figure-label">People active</span>
                ${valueLine(formatNumber(trend.count), change(trend, before))}
                <span class="stream-sparkline" aria-hidden="true"></span>
            </div>
        `;
    }

    /**
     * The days with activity in the window, on the board of one user: the people active would always be one.
     */
    private activeDays(): TemplateResult {
        const trend = this.statistics.activeDays;
        const before = `${formatNumber(trend.previous)} ${comparedWith(this.statistics)}`;

        return html`
            <div class="stream-figure" title="The days with activity">
                <span class="stream-figure-label">Active days</span>
                ${valueLine(formatNumber(trend.count), change(trend, before))}
                ${sparkline(trend.values, 'total', { soFar: this.statistics.window.open })}
            </div>
        `;
    }

    /**
     * How widely the people spread the merges and the votes: the fewest people who did half of them. The work should
     * rest on many shoulders, so more carriers are better.
     */
    private crews(): TemplateResult | typeof nothing {
        const crews = this.statistics.crews;
        if (crews.length === 0) {
            return nothing;
        }

        return html`
            <section class="stream-chart stream-widget is-m" aria-label="Who carries the review">
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">Who carries the review</h3>
                        <p class="stream-chart-note">How many people did half of the work. The more, the better.</p>
                    </div>
                </header>
                <dl class="stream-crews">${crews.map(crew => this.crew(crew))}</dl>
            </section>
        `;
    }

    private crew(crew: ActivityCrew): TemplateResult {
        const work = crew.label.toLowerCase();
        if (crew.carriers === 0) {
            return html`
                <div class="stream-crew" title=${crew.description}>
                    <dt class="stream-figure-label">${crew.label}</dt>
                    <dd class="stream-crew-note">No ${work} in the window</dd>
                </div>
            `;
        }

        return html`
            <div class="stream-crew" title=${crew.description}>
                <dt class="stream-figure-label">${crew.label}</dt>
                <dd>${valueLine(formatNumber(crew.carriers), this.carriersChange(crew))}</dd>
                <dd>${carrierMeter(crew.carrierShares)}</dd>
                <dd class="stream-crew-note">
                    Half of the ${work} came from ${formatNumber(crew.carriers)} of ${formatNumber(crew.people)}
                    ${crew.people === 1 ? 'person' : 'people'}
                </dd>
            </div>
        `;
    }

    /**
     * The change in carriers as a difference: they are few, and "+1" reads better than "+50 %".
     */
    private carriersChange(crew: ActivityCrew): TemplateResult | typeof nothing {
        if (crew.previousCarriers === 0) {
            return nothing;
        }
        const difference = crew.carriers - crew.previousCarriers;
        const text = difference > 0 ? `+${formatNumber(difference)}` : formatNumber(difference);
        const title = `${formatNumber(crew.previousCarriers)} ${comparedWith(this.statistics)}`;

        return badge(text, difference, crew.better, title);
    }

    /**
     * A stock, like the open issues: how many are open now, the change since the start of the window, and how many
     * were open at the end of each bar of time. The most important figure of the board: it goes on top.
     */
    private open(label: string, trend: ActivityTrend, source: Source): TemplateResult {
        const { buckets, window } = this.statistics;
        const difference = trend.count - trend.previous;
        const text = difference > 0 ? `+${formatNumber(difference)}` : formatNumber(difference);
        const since = `since ${startLabel(this.statistics)}`;
        const before = `${formatNumber(trend.previous)} open on ${startLabel(this.statistics)}`;
        const values = trend.values;
        const lowest = Math.min(...values);
        const highest = Math.max(...values);
        const step = Math.max(1, Math.ceil(buckets.length / OPEN_TICKS));

        return html`
            <section class="stream-chart stream-widget is-l" aria-label=${label}>
                <h3 class="stream-chart-title stream-open-title">
                    <span class="stream-legend-swatch" style="background: var(--stream-${source})"></span>
                    ${label}
                </h3>
                <p class="stream-chart-note">Open at the end of each ${window.interval}</p>
                <span class="stream-figure-line">
                    <span class="stream-figure-value">${formatNumber(trend.count)}</span>
                    <span class="stream-open-change">
                        ${badge(text, difference, trend.better, before)} ${since}
                    </span>
                </span>
                <div class="stream-axis-frame">
                    <div class="stream-axis" aria-hidden="true">
                        <span>${formatNumber(highest)}</span>
                        <span>${formatNumber(lowest)}</span>
                    </div>
                    <div class="stream-bars stream-line-chart" aria-hidden="true">
                        ${openLine(values, lowest, highest, source)}
                        ${buckets.map((key, bucket) => html`
                            <span
                                class="stream-bar stream-line-hit"
                                data-tip=${`${bucketLabel(key, window.interval)}: ${openCount(values[bucket])}`}
                            >
                                ${bucket % step === 0
                                    ? html`<span class="stream-bar-tick">${bucketTick(key, window.interval)}</span>`
                                    : nothing}
                            </span>
                        `)}
                    </div>
                </div>
            </section>
        `;
    }

    /**
     * How long work waits: the median, its change to the time before, and how many waits took how long. A long wait
     * is one of many in its band: it does not distort the picture.
     */
    private duration(duration: ActivityDuration): TemplateResult {
        const compared = comparedWith(this.statistics);
        const comparable = duration.median !== null && duration.previous !== null && duration.previous !== 0;
        const trend: ActivityTrend = {
            count: duration.median ?? 0,
            previous: comparable ? duration.previous ?? 0 : 0,
            values: [],
            better: duration.better,
        };
        const median = duration.median === null ? '–' : formatDuration(duration.median);
        const previous = duration.previous === null ? '' : `${formatDuration(duration.previous)} ${compared}`;

        return html`
            <section class="stream-chart stream-widget is-l" aria-label=${duration.label} title=${duration.description}>
                <h3 class="stream-chart-title">${duration.label}</h3>
                <p class="stream-chart-note">Median of the ${formatNumber(duration.count)} that ended in the window</p>
                ${valueLine(median, comparable ? change(trend, previous) : nothing)}
                ${waitBands(duration)}
            </section>
        `;
    }

    /**
     * The open changes that wait for a review now, by the time since their last activity. Beside the waits that
     * ended: these have not.
     */
    private reviewQueue(): TemplateResult {
        const queue = this.statistics.reviewQueue;
        const waiting = queue.reduce((total, age) => total + age.count, 0);
        const bands = queue.map(age => ({ ...age, kinds: [] }));

        return html`
            <section class="stream-chart stream-widget is-l" aria-label="Changes waiting for review">
                <h3 class="stream-chart-title">Changes waiting for review</h3>
                <p class="stream-chart-note">Open now, by their last activity, without work in progress</p>
                ${valueLine(formatNumber(waiting), nothing)}
                ${bandRows(bands, 'gerrit')}
            </section>
        `;
    }

    private figure(figure: ActivityFigure): TemplateResult {
        const before = `${formatNumber(figure.previous)} ${comparedWith(this.statistics)}`;
        const events: ActivityChange = { view: 'stream', source: figure.source, kinds: figure.kinds };

        return html`
            <a
                class="stream-figure"
                href=${withQuery(this.streamUrl, pageParameters(applyChange(this.state, events)))}
                title="${figure.description}: show them"
                @click=${(event: MouseEvent) => handledByPage(event, this, events)}
            >
                ${figureLabel(figure.label, figure.source)}
                ${valueLine(formatNumber(figure.count), change(figure, before))}
                ${sparkline(figure.values, figure.source, { soFar: this.statistics.window.open })}
            </a>
        `;
    }
}

/**
 * How many waits took how long, a bar per band. Where the kind of event that ended the waits matters, like a change
 * merged or abandoned, the bar splits by it in the colors of the kinds.
 */
function waitBands(duration: ActivityDuration): TemplateResult {
    const kinds = duration.bands.flatMap(band => band.kinds)
        .filter((kind, index, all) => all.findIndex(other => other.key === kind.key) === index);

    return html`
        ${kinds.length < 2 ? nothing : html`
            <ul class="stream-legend stream-wait-legend">
                ${kinds.map(kind => html`
                    <li>
                        <span class="stream-legend-swatch" style="background: ${kindColor(kind.key)}"></span>
                        ${kind.label}
                    </li>
                `)}
            </ul>
        `}
        ${bandRows(duration.bands, duration.source)}
    `;
}

/**
 * A row per band: its label, its bar and its count.
 */
function bandRows(bands: ActivityWaitBand[], source: Source): TemplateResult {
    const largest = Math.max(1, ...bands.map(band => band.count));

    return html`
        <div class="stream-wait-bands">
            ${bands.map(band => {
                const parts = band.kinds.map(kind => `${kind.label} ${formatNumber(kind.count)}`).join(', ');
                const tip = `${band.label}: ${formatNumber(band.count)}` + (parts === '' ? '' : ` (${parts})`);

                return html`
                    <div class="stream-wait-band" data-tip=${tip}>
                        <span class="stream-wait-band-label">${band.label}</span>
                        <span class="stream-wait-band-track">${bandBar(band, largest, source)}</span>
                        <span class="stream-wait-band-count">${formatNumber(band.count)}</span>
                    </div>
                `;
            })}
        </div>
    `;
}

/**
 * The bar of a band, as long as its share of the largest band: in the color of the channel, or split by kind.
 */
function bandBar(band: ActivityWaitBand, largest: number, source: Source): TemplateResult | typeof nothing {
    if (band.count === 0) {
        return nothing;
    }
    const parts = band.kinds.length === 0
        ? [html`<span style="flex-grow: 1; background: var(--stream-${source})"></span>`]
        : band.kinds.map(kind => html`
            <span style="flex-grow: ${kind.count}; background: ${kindColor(kind.key)}"></span>
        `);

    return html`<span class="stream-wait-band-bar" style="width: ${(band.count / largest) * 100}%">${parts}</span>`;
}

function kindColor(key: string): string {
    return `var(--stream-kind-${key})`;
}

/**
 * The label of a tile, with the swatch of its channel: the tiles of both channels share the rows of the grid.
 */
function figureLabel(label: string, source: Source): TemplateResult {
    const channel = sources().find(candidate => candidate.value === source);

    return html`
        <span class="stream-figure-label">
            <span
                class="stream-legend-swatch"
                style="background: var(--stream-${source})"
                title=${channel?.label ?? source}
            ></span>
            ${label}
        </span>
    `;
}

function openCount(value: number | undefined): string {
    return `${formatNumber(value ?? 0)} open`;
}

/**
 * The line of a stock from its lowest to its highest value, over the whole width: a point in the middle of each bar
 * of time, where the pointer finds its tooltip.
 */
function openLine(values: number[], lowest: number, highest: number, colorKey: string): TemplateResult {
    const range = highest - lowest || 1;
    const points = values.map((value, index) => {
        const x = ((index + 0.5) / values.length) * 100;
        const y = 100 - ((value - lowest) / range) * 100;

        return `${x.toFixed(2)},${y.toFixed(2)}`;
    });
    const first = points[0]?.split(',')[0] ?? '0';
    const last = points[points.length - 1]?.split(',')[0] ?? '100';
    const style = `var(--stream-${colorKey})`;

    return html`
        <svg class="stream-line" viewBox="0 0 100 100" preserveAspectRatio="none">
            <polygon points="${first},100 ${points.join(' ')} ${last},100" style="fill: ${style}"></polygon>
            <polyline points=${points.join(' ')} style="stroke: ${style}"></polyline>
        </svg>
    `;
}

/**
 * How a sparkline draws its values. A window that ends now draws its last bit dashed: the time of the last bucket
 * is not over.
 */
interface SparklineOptions {
    soFar?: boolean;
}

/**
 * The line of the values, as high as the highest one. 2 pixels, whatever the width. Without values it keeps its
 * place, so the tiles of a row stay alike.
 */
export function sparkline(values: (number | null)[], colorKey: string, options: SparklineOptions = {}): TemplateResult {
    if (values.length < 2) {
        return html`<span class="stream-sparkline" aria-hidden="true"></span>`;
    }
    const { soFar = false } = options;
    const numbers = values.filter((value): value is number => value !== null);
    const max = Math.max(1, ...numbers);
    const min = Math.min(0, ...numbers);
    const step = 100 / (values.length - 1);
    // A bucket without a value breaks the line, it does not drop to zero
    const runs: string[][] = [[]];
    values.forEach((value, index) => {
        if (value === null) {
            runs.push([]);
            return;
        }
        const y = 28 - ((value - min) / (max - min || 1)) * 26;
        runs[runs.length - 1]?.push(`${(index * step).toFixed(2)},${y.toFixed(2)}`);
    });
    const lastValue = values[values.length - 1] ?? null;
    const last = runs[runs.length - 1] ?? [];
    // The bit of the last bucket leaves the run and gets a dashed line of its own
    const lastBit = soFar && lastValue !== null && last.length >= 2 ? last.splice(-2, 2) : [];
    if (lastBit.length === 2) {
        last.push(lastBit[0] ?? '');
    }
    const style = `var(--stream-${colorKey})`;

    return html`
        <svg class="stream-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">
            ${runs.filter(run => run.length > 0).map(run => run.length === 1
                ? svg`<circle cx=${run[0]?.split(',')[0]} cy=${run[0]?.split(',')[1]} r="1.5"
                    style="fill: ${style}"></circle>`
                : svg`
                    <polygon points=${area(run)} style="fill: ${style}"></polygon>
                    <polyline points=${run.join(' ')} style="stroke: ${style}"></polyline>
                `)}
            ${lastBit.length === 2
                ? svg`<polyline class="is-so-far" points=${lastBit.join(' ')} style="stroke: ${style}"></polyline>`
                : nothing}
        </svg>
    `;
}

/**
 * The area below a run of the line, down to the bottom of the sparkline.
 */
function area(run: string[]): string {
    const first = run[0]?.split(',')[0] ?? '0';
    const last = run[run.length - 1]?.split(',')[0] ?? '100';

    return `${first},30 ${run.join(' ')} ${last},30`;
}

/**
 * The way a figure gets better in words, for the title of its change. The API names the way, see
 * App\Enum\StreamBetterEnum.
 */
const BETTER: Record<Better, string> = {
    more: 'More is better',
    less: 'Less is better',
    neither: 'Neither better nor worse',
};

/**
 * The number of a figure and, on its right, its change.
 */
export function valueLine(value: string, change: TemplateResult | typeof nothing): TemplateResult {
    return html`
        <span class="stream-figure-line">
            <span class="stream-figure-value">${value}</span>
            ${change}
        </span>
    `;
}

/**
 * The share of each carrier as a segment of a bar, the busiest first, with a mark at half of the work.
 */
function carrierMeter(shares: number[]): TemplateResult {
    const segment = (share: number): TemplateResult => html`
        <span style="width: ${share}%" title="${formatNumber(Math.round(share))} %"></span>
    `;

    return html`<span class="stream-meter" aria-hidden="true">${shares.map(segment)}</span>`;
}

/**
 * The change as a badge: green when the figure got better, red when it got worse, grey when it stayed, is too small
 * to notice or has no better way.
 */
export function badge(text: string, difference: number, better: Better, title: string, noticeable = true): TemplateResult {
    const direction = difference > 0 ? 'actions-arrow-up' : difference < 0 ? 'actions-arrow-down' : 'actions-minus';
    const improved = better === 'more' ? difference > 0 : difference < 0;
    const neutral = better === 'neither' || difference === 0 || !noticeable;
    const tone = neutral ? 'neutral' : improved ? 'better' : 'worse';

    return html`
        <span class="stream-figure-delta is-${tone}" title="${title}. ${BETTER[better]}.">
            ${icon(direction)} ${text}
        </span>
    `;
}

/**
 * A change below this share stays grey: a few percent up or down is the noise of an ordinary week.
 */
const NOTICEABLE_PERCENT = 5;

/**
 * The change to the time before in percent. Nothing without a time before to compare with.
 */
export function change(trend: ActivityTrend, title: string): TemplateResult | typeof nothing {
    if (trend.previous === 0) {
        return nothing;
    }
    const percent = Math.round(((trend.count - trend.previous) / trend.previous) * 100);
    // Beyond ten times as many a percentage reads as noise
    const times = trend.count / trend.previous;
    const text = times >= 10 ? `× ${formatNumber(Math.round(times))}` : percent > 0 ? `+${percent} %` : `${percent} %`;

    return badge(text, percent, trend.better, title, Math.abs(percent) >= NOTICEABLE_PERCENT);
}

/**
 * What the changes compare with: "vs. previous 30 days". The window before ends at the same time of day.
 */
export function comparedWith(statistics: ActivityStatistics): string {
    const days = statistics.window.days;

    return `vs. previous ${days} ${days === 1 ? 'day' : 'days'}`;
}

const day = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' });
const dayOfYear = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

/**
 * The first day of the window, with its year when that is not the current one.
 */
function startLabel(statistics: ActivityStatistics): string {
    const from = new Date(statistics.window.from);

    return (from.getFullYear() === new Date().getFullYear() ? day : dayOfYear).format(from);
}

/**
 * A wait as a reader reads it: minutes, hours, days.
 */
export function formatDuration(seconds: number): string {
    if (seconds < 3600) {
        return `${Math.max(1, Math.round(seconds / 60))} min`;
    }
    if (seconds < 48 * 3600) {
        return `${Math.round(seconds / 3600)} h`;
    }
    const days = seconds / 86_400;

    const whole = Number.isInteger(days) || days >= 10;

    return `${whole ? formatNumber(Math.round(days)) : days.toFixed(1)} days`;
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-figures': ActivityFigures;
    }
}
