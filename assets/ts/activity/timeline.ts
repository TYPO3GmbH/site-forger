import { html, nothing, type TemplateResult } from 'lit';
import { customElement } from 'lit/decorators.js';
import { StatisticsCard } from './card';
import { bucketDays, bucketLabel, bucketTick, formatNumber, isSoFar, soFarTip } from './format';
import { handledByPage } from './light-element';
import { medianKey, medianLine, medianOf } from './scale';
import { pageParameters, withQuery, type ActivityChange } from './state';
import { withTooltips } from './tooltip';
import type { Interval, Split } from './types';

withTooltips();

/**
 * About six names along the axis, so they do not collide on a phone.
 */
const TICKS = 6;

const SPLITS: { value: Split; label: string }[] = [
    { value: 'total', label: 'Total' },
    { value: 'source', label: 'By channel' },
    { value: 'kind', label: 'By kind' },
];

/**
 * "Activity over time": the events as stacked bars, one per hour, day, week or month, all of them or split by
 * channel or kind, with a legend for more than one series. A bar opens the stream of its time. The colors are the
 * custom properties "--stream-<key>" of assets/css/module/_stream.scss.
 */
@customElement('forger-activity-timeline')
export class ActivityTimeline extends StatisticsCard {
    protected override interval(): Interval | null {
        return this.state.timelineInterval;
    }

    protected override intervalChange(interval: Interval | null): ActivityChange {
        return { timelineInterval: interval };
    }

    protected override split(): Split {
        return this.state.split;
    }

    protected override render(): TemplateResult {
        const { window } = this.data();

        return html`
            <section class="stream-chart" aria-label="Activity over time" aria-busy=${this.busy()}>
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">Activity over time</h3>
                        <p class="stream-chart-note">Events per ${window.interval}, in ${window.timeZone}</p>
                    </div>
                    <div class="stream-chart-controls">
                        <div class="stream-segmented" role="group" aria-label="Split the events">
                            ${SPLITS.map(split => html`
                                <a
                                    class="stream-segment${this.state.split === split.value ? ' is-active' : ''}"
                                    href=${this.href({ split: split.value })}
                                    aria-current=${this.state.split === split.value ? 'true' : nothing}
                                    @click=${(event: MouseEvent) => handledByPage(event, this, { split: split.value })}
                                >${split.label}</a>
                            `)}
                        </div>
                        ${this.intervalSwitch()}
                        ${this.tableSwitch('Activity over time')}
                    </div>
                </header>
                ${this.showTable ? this.table() : this.chart()}
            </section>
        `;
    }

    private chart(): TemplateResult {
        const { buckets, series, window } = this.data();
        const totals = buckets.map((_, bucket) => series.reduce((sum, one) => sum + (one.values[bucket] ?? 0), 0));
        const top = Math.max(0, ...totals);
        // The bar of a time not over yet counts too little: the median leaves it out
        const median = medianOf(window.open ? totals.slice(0, -1) : totals);
        const step = Math.max(1, Math.ceil(buckets.length / TICKS));
        const split = series.length > 1;

        return html`
            ${split ? html`
                <ul class="stream-legend">
                    ${series.filter(one => one.values.some(value => value > 0)).map(one => html`
                        <li>
                            <span
                                class="stream-legend-swatch"
                                style="background: var(--stream-${one.key})"
                            ></span>${one.label}
                        </li>
                    `)}
                </ul>
            ` : nothing}
            <div class="stream-bars-frame">
                <span class="stream-bars-max" aria-hidden="true">${formatNumber(top)}</span>
                ${median === null ? nothing : medianKey(`${formatNumber(Math.round(median))} per ${window.interval}`)}
                <div class="stream-bars" aria-hidden="true">
                    ${medianLine(median, top)}
                    ${buckets.map((key, bucket) => {
                        const parts = series.filter(one => (one.values[bucket] ?? 0) > 0)
                            .map(one => `${one.label} ${formatNumber(one.values[bucket] ?? 0)}`);
                        const tip = bucketLabel(key, window.interval) + ': ' + formatNumber(totals[bucket] ?? 0)
                            + (split && parts.length > 0 ? ` (${parts.join(', ')})` : '');
                        const total = totals[bucket] ?? 0;
                        const soFar = isSoFar(window, bucket, buckets.length);

                        return html`
                            <a
                                class="stream-bar ${soFar ? 'is-so-far' : ''}"
                                href=${this.dayUrl(key)}
                                data-tip=${soFarTip(tip, soFar)}
                                tabindex="-1"
                                @click=${(event: MouseEvent) => handledByPage(event, this, this.dayChange(key))}
                            >
                                <span class="stream-bar-stack" style="height: ${top === 0 ? 0 : (total / top) * 100}%">
                                    ${[...series].reverse().map(one => (one.values[bucket] ?? 0) > 0 ? html`
                                        <span
                                            style="flex-grow: ${one.values[bucket]};
                                                background: var(--stream-${one.key})"
                                        ></span>
                                    ` : nothing)}
                                </span>
                                ${bucket % step === 0
                                    ? html`<span class="stream-bar-tick">${bucketTick(key, window.interval)}</span>`
                                    : nothing}
                            </a>
                        `;
                    })}
                </div>
            </div>
        `;
    }

    private table(): TemplateResult {
        const { buckets, series, window } = this.data();
        const split = series.length > 1;
        const totals = buckets.map((_, bucket) => series.reduce((sum, one) => sum + (one.values[bucket] ?? 0), 0));

        return html`
            <div class="stream-table-scroll" tabindex="0">
                <table class="overview-data-table">
                    <thead>
                        <tr>
                            <th scope="col">${window.interval[0]?.toUpperCase()}${window.interval.slice(1)}</th>
                            ${split ? series.map(one => html`<th scope="col">${one.label}</th>`) : nothing}
                            <th scope="col">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${[...buckets.keys()].reverse().map(bucket => html`
                            <tr>
                                <th scope="row">
                                    <a
                                        href=${this.dayUrl(buckets[bucket] ?? '')}
                                        @click=${(event: MouseEvent) => handledByPage(
                                            event,
                                            this,
                                            this.dayChange(buckets[bucket] ?? ''),
                                        )}
                                    >${bucketLabel(buckets[bucket] ?? '', window.interval)}</a>
                                </th>
                                ${split
                                    ? series.map(one => html`<td>${formatNumber(one.values[bucket] ?? 0)}</td>`)
                                    : nothing}
                                <td>${formatNumber(totals[bucket] ?? 0)}</td>
                            </tr>
                        `)}
                    </tbody>
                </table>
            </div>
        `;
    }

    private dayChange(key: string): ActivityChange {
        return { ...bucketDays(key, this.data().window.interval), view: 'stream' };
    }

    private dayUrl(key: string): string {
        return withQuery(this.streamUrl, pageParameters({ ...this.state, ...this.dayChange(key) }));
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-timeline': ActivityTimeline;
    }
}
