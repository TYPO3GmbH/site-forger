import { html, nothing, svg, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { fetchClosedYears } from './api';
import { tableSwitch } from './card';
import { change } from './figures';
import { formatNumber } from './format';
import { LightElement } from './light-element';
import { Loader } from './loader';
import { withTooltips } from './tooltip';
import type { ActivityClosedYear, ActivityClosedYears, Source } from './types';

withTooltips();

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * A name below every second month, so they do not collide on a phone.
 */
const TICK_STEP = 2;

interface Card {
    source: Source;
    title: string;
    note: string;
    years: ActivityClosedYear[];
}

/**
 * "Closed per year": the issues and the changes closed in this year and the two before, summed up month by month,
 * a line per year. The current year is the darkest, "--stream-year-0" of assets/css/module/_stream.scss. It does
 * not depend on the filter: it loads once. The element has no box of its own: its two cards are widgets of the
 * dashboard grid around it, see ".stream-dashboard".
 */
@customElement('forger-activity-closed-years')
export class ActivityClosedYearsElement extends LightElement {
    @property({ attribute: 'api-url' }) apiUrl = '';

    @state() private tables: Record<Source, boolean> = { forge: false, gerrit: false };
    /**
     * The years the reader took out of a chart.
     */
    @state() private off: Record<Source, number[]> = { forge: [], gerrit: [] };

    private readonly loader = new Loader<ActivityClosedYears>(this);

    protected override willUpdate(changed: PropertyValues<this>): void {
        if (changed.has('apiUrl') && this.apiUrl !== '') {
            void this.loader.load(signal => fetchClosedYears(this.apiUrl, signal));
        }
    }

    protected override render(): TemplateResult | typeof nothing {
        const data = this.loader.data;
        if (data === null) {
            return this.loader.error === null
                ? nothing
                : html`<p class="stream-empty stream-widget is-full">${this.loader.error}</p>`;
        }
        const cards: Card[] = [
            {
                source: 'forge',
                title: 'Closed issues per year',
                note: 'By the day they first closed, summed up month by month',
                years: data.issues,
            },
            {
                source: 'gerrit',
                title: 'Closed changes per year',
                note: 'Merged or abandoned, summed up month by month',
                years: data.changes,
            },
        ];

        return html`${cards.map(card => this.card(card))}`;
    }

    private card(card: Card): TemplateResult {
        const table = this.tables[card.source];

        return html`
            <section class="stream-chart stream-widget is-l" aria-label=${card.title}>
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">${card.title}</h3>
                        <p class="stream-chart-note">${card.note}</p>
                    </div>
                    ${tableSwitch(card.title, table, show => {
                        this.tables = { ...this.tables, [card.source]: show };
                    })}
                </header>
                ${soFar(card.years)}
                ${table
                    ? yearsTable(card.years)
                    : yearsChart(card.years, this.off[card.source], year => this.toggle(card.source, year))}
            </section>
        `;
    }

    private toggle(source: Source, year: number): void {
        const off = this.off[source];
        const next = off.includes(year) ? off.filter(other => other !== year) : [...off, year];
        this.off = { ...this.off, [source]: next };
    }
}

/**
 * The last month of a year that has a sum, -1 without one.
 */
function lastMonth(year: ActivityClosedYear): number {
    return year.closed.reduce<number>((last, value, month) => value === null ? last : month, -1);
}

/**
 * The sum of the current year, and its change to the year before. They compare at the end of the last month that
 * is over: the current month is not.
 */
function soFar(years: ActivityClosedYear[]): TemplateResult | typeof nothing {
    const [current, before] = years;
    if (current === undefined) {
        return nothing;
    }
    const month = lastMonth(current);
    const whole = month - 1;
    const compared = before === undefined || whole < 0
        ? nothing
        : html`
            ${change(
                { count: current.closed[whole] ?? 0, previous: before.closed[whole] ?? 0, values: [], better: 'more' },
                `${formatNumber(before.closed[whole] ?? 0)} by the end of ${MONTHS[whole]} ${before.year}`,
            )}
            vs. ${before.year}, by the end of ${MONTHS[whole]}
        `;

    return html`
        <span class="stream-figure-line">
            <span class="stream-figure-value">${formatNumber(current.closed[month] ?? 0)}</span>
            <span class="stream-open-change">${compared}</span>
        </span>
        <p class="stream-chart-note">Closed in ${current.year} so far</p>
    `;
}

/**
 * The lines of the years. A click on a year of the legend takes its line out or puts it back: the scale follows
 * the years shown. A year keeps its color, shown or not. The last year shown stays.
 */
function yearsChart(years: ActivityClosedYear[], hidden: number[], toggle: (year: number) => void): TemplateResult {
    const shown = years.filter(year => !hidden.includes(year.year));
    const highest = Math.max(1, ...shown.flatMap(year => year.closed.map(value => value ?? 0)));
    const thisMonth = new Date().getMonth();
    const thisYear = new Date().getFullYear();
    const tip = (month: number): string => [
        `By the end of ${MONTHS[month]}`,
        ...shown
            .filter(year => year.closed[month] !== null && year.closed[month] !== undefined)
            .map(year => `${year.year}: ${formatNumber(year.closed[month] ?? 0)}`
                + (year.year === thisYear && month === thisMonth ? ' so far' : '')),
    ].join('\n');

    return html`
        <ul class="stream-legend stream-years-legend">
            ${years.map((year, index) => {
                const on = !hidden.includes(year.year);

                return html`
                    <li>
                        <button
                            type="button"
                            class="stream-legend-toggle${on ? '' : ' is-off'}"
                            aria-pressed=${on ? 'true' : 'false'}
                            title=${on ? `Hide ${year.year}` : `Show ${year.year}`}
                            ?disabled=${on && shown.length === 1}
                            @click=${() => toggle(year.year)}
                        >
                            <span class="stream-legend-swatch" style="--swatch: var(--stream-year-${index})"></span>
                            ${year.year}
                        </button>
                    </li>
                `;
            })}
        </ul>
        <div class="stream-axis-frame">
            <div class="stream-axis" aria-hidden="true">
                <span>${formatNumber(highest)}</span>
                <span>0</span>
            </div>
            <div class="stream-bars stream-line-chart" aria-hidden="true">
                <svg class="stream-line" viewBox="0 0 100 100" preserveAspectRatio="none">
                    ${years.map((year, index) => hidden.includes(year.year) ? nothing : svg`
                        <polyline
                            points=${points(year.closed, highest)}
                            style="stroke: var(--stream-year-${index})"
                        ></polyline>
                    `).reverse()}
                </svg>
                ${MONTHS.map((name, month) => html`
                    <span class="stream-bar stream-line-hit" data-tip=${tip(month)}>
                        ${month % TICK_STEP === 0 ? html`<span class="stream-bar-tick">${name}</span>` : nothing}
                    </span>
                `)}
            </div>
        </div>
    `;
}

/**
 * The line of a year: a point in the middle of each month, where the pointer finds its tooltip. It ends with the
 * last month that has a sum.
 */
function points(closed: (number | null)[], highest: number): string {
    return closed
        .map((value, month) => value === null
            ? null
            : `${(((month + 0.5) / closed.length) * 100).toFixed(2)},${(100 - (value / highest) * 100).toFixed(2)}`)
        .filter(point => point !== null)
        .join(' ');
}

function yearsTable(years: ActivityClosedYear[]): TemplateResult {
    return html`
        <div class="stream-table-scroll" tabindex="0">
            <table class="overview-data-table">
                <thead>
                    <tr>
                        <th scope="col">By the end of</th>
                        ${years.map(year => html`<th scope="col">${year.year}</th>`)}
                    </tr>
                </thead>
                <tbody>
                    ${MONTHS.map((name, month) => html`
                        <tr>
                            <th scope="row">${name}</th>
                            ${years.map(year => {
                                const value = year.closed[month] ?? null;

                                return value === null
                                    ? html`<td class="is-empty">–</td>`
                                    : html`<td>${formatNumber(value)}</td>`;
                            })}
                        </tr>
                    `)}
                </tbody>
            </table>
        </div>
    `;
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-closed-years': ActivityClosedYearsElement;
    }
}
