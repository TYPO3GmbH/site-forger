import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { fetchDays, fetchWeekHours } from './api';
import { tableSwitch } from './card';
import './calendar';
import { firstYear } from './definitions';
import './heatmap';
import { handledByPage, LightElement } from './light-element';
import { Loader } from './loader';
import { menu } from './menu';
import { applyChange, pageParameters, withQuery, type ActivityChange, type ActivityState } from './state';
import type { ActivityCalendar, ActivityWeekHours, Range } from './types';

const HOURS: { value: Range; label: string; title: string }[] = [
    { value: '30d', label: '30 d', title: 'The last 30 days' },
    { value: '6m', label: '6 m', title: 'The last 6 months' },
    { value: '1y', label: '1 y', title: 'The last year' },
];

/**
 * A part of the statistics that loads its own data with the API, for a window of its own: the patterns of the
 * activity need more time than the window of the page may have.
 */
abstract class PatternSection<T> extends LightElement {
    @property({ attribute: false }) state!: ActivityState;
    @property({ attribute: 'api-url' }) apiUrl = '';
    @property({ attribute: 'statistics-url' }) statisticsUrl = '';

    private readonly loader = new Loader<T>(this);

    protected get data(): T | null {
        return this.loader.data;
    }

    protected get error(): string | null {
        return this.loader.error;
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        if ((changed.has('state') && this.changesData(changed.get('state'))) || changed.has('apiUrl')) {
            void this.load();
        }
    }

    protected abstract fetch(signal: AbortSignal): Promise<T>;

    /**
     * Whether the state before differs in what the data depends on: the page window does not count.
     */
    protected abstract changesData(before: ActivityState | undefined): boolean;

    protected href(change: ActivityChange): string {
        return withQuery(this.statisticsUrl, pageParameters(applyChange(this.state, change)));
    }

    protected sameFilter(before: ActivityState | undefined): boolean {
        if (before === undefined) {
            return false;
        }
        const keys: (keyof ActivityState)[] = ['source', 'branch', 'person', 'bots'];

        return keys.every(key => before[key] === this.state[key]) && before.kinds.join() === this.state.kinds.join();
    }

    private load(): Promise<boolean> {
        return this.loader.load(signal => this.fetch(signal));
    }
}

/**
 * "When people are active", or "When you are active" on the board of one user: the events per weekday and hour,
 * for 30 days, 6 months or a year of its own.
 */
@customElement('forger-activity-hours')
export class ActivityHours extends PatternSection<ActivityWeekHours> {
    protected override fetch(signal: AbortSignal): Promise<ActivityWeekHours> {
        return fetchWeekHours(this.apiUrl, this.state, signal);
    }

    protected override changesData(before: ActivityState | undefined): boolean {
        return !this.sameFilter(before) || before?.hours !== this.state.hours;
    }

    protected override render(): TemplateResult {
        const title = this.state.own === null ? 'When people are active' : 'When you are active';

        return html`
            <section class="stream-chart" aria-label=${title}>
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">${title}</h3>
                        <p class="stream-chart-note">
                            Events per weekday and hour, in ${this.data?.window.timeZone ?? 'your time zone'}
                        </p>
                    </div>
                    <div class="stream-segmented" role="group" aria-label="Time of the hours">
                        ${HOURS.map(hours => html`
                            <a
                                class="stream-segment${this.state.hours === hours.value ? ' is-active' : ''}"
                                href=${this.href({ hours: hours.value })}
                                title=${hours.title}
                                aria-current=${this.state.hours === hours.value ? 'true' : nothing}
                                @click=${(event: MouseEvent) => handledByPage(event, this, { hours: hours.value })}
                            >${hours.label}</a>
                        `)}
                    </div>
                </header>
                ${this.error !== null ? html`<p class="stream-empty">${this.error}</p>` : nothing}
                ${this.data === null ? nothing : html`
                    <forger-activity-heatmap
                        .weekHours=${this.data.weekHours}
                        time-zone=${this.data.window.timeZone}
                    ></forger-activity-heatmap>
                `}
            </section>
        `;
    }
}

/**
 * "Over the year": the events per day of a year as a calendar, the last 12 months or a year picked.
 */
@customElement('forger-activity-year')
export class ActivityYear extends PatternSection<ActivityCalendar> {
    @property({ attribute: 'stream-url' }) streamUrl = '';
    @state() private showTable = false;

    protected override fetch(signal: AbortSignal): Promise<ActivityCalendar> {
        return fetchDays(this.apiUrl, this.state, signal);
    }

    protected override changesData(before: ActivityState | undefined): boolean {
        return !this.sameFilter(before) || before?.year !== this.state.year;
    }

    protected override render(): TemplateResult {
        const current = new Date().getFullYear();
        const years = Array.from({ length: current - firstYear() + 1 }, (_, index) => current - index);

        return html`
            <section class="stream-chart" aria-label="Over the year">
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">Over the year</h3>
                        <p class="stream-chart-note">
                            Events per day, in ${this.data?.window.timeZone ?? 'your time zone'}
                        </p>
                    </div>
                    <div class="stream-chart-controls">
                        ${menu(
                            {
                                label: String(this.state.year ?? 'Last 12 months'),
                                name: 'Year',
                                title: 'Pick a year',
                                end: true,
                                panel: 'stream-year-panel',
                            },
                            html`
                                ${this.yearLink(null, 'Last 12 months')}
                                <div class="stream-year-choices">
                                    ${years.map(year => this.yearLink(year, String(year)))}
                                </div>
                            `,
                        )}
                        ${tableSwitch('Over the year', this.showTable, table => { this.showTable = table; })}
                    </div>
                </header>
                ${this.error !== null ? html`<p class="stream-empty">${this.error}</p>` : nothing}
                ${this.data === null ? nothing : html`
                    <forger-activity-calendar
                        .calendar=${this.data}
                        .state=${this.state}
                        stream-url=${this.streamUrl}
                        ?table=${this.showTable}
                    ></forger-activity-calendar>
                `}
            </section>
        `;
    }

    /**
     * A year of the menu: a link that the page follows in place. The menu closes.
     */
    private yearLink(year: number | null, label: string): TemplateResult {
        const active = this.state.year === year;

        return html`
            <a
                class="stream-year-choice${active ? ' is-active' : ''}"
                href=${this.href({ year })}
                aria-current=${active ? 'true' : nothing}
                data-menu-close
                @click=${(event: MouseEvent) => handledByPage(event, this, { year })}
            >${label}</a>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-hours': ActivityHours;
        'forger-activity-year': ActivityYear;
    }
}
