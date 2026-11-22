import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { fetchStatistics } from './api';
import './closed-years';
import './filter';
import { comparedWith, type FigureGroup } from './figures';
import './flow';
import './newcomers';
import './patterns';
import { LightElement } from './light-element';
import { Loader } from './loader';
import { type ActivityState } from './state';
import './timeline';
import type { ActivityStatistics as Statistics } from './types';

const REFRESH_SECONDS = 60;

/**
 * The statistics of the activity: one board of all of it, the key figures, the waits, what came in and went out,
 * the new contributors, the events over time and per weekday and hour, what was closed over the years. Only the
 * time window changes it: so each widget has its fixed place, see ".stream-dashboard". It loads them with the API
 * and renders them again every minute while the window ends now.
 */
@customElement('forger-activity-statistics')
export class ActivityStatisticsElement extends LightElement {
    @property({ attribute: false }) state!: ActivityState;
    @property({ attribute: 'api-url' }) apiUrl = '';
    @property({ attribute: 'statistics-url' }) statisticsUrl = '';
    @property({ attribute: 'stream-url' }) streamUrl = '';
    @property({ attribute: 'week-hours-api-url' }) weekHoursApiUrl = '';
    @property({ attribute: 'days-api-url' }) daysApiUrl = '';
    @property({ attribute: 'newcomers-api-url' }) newcomersApiUrl = '';
    @property({ attribute: 'closed-years-api-url' }) closedYearsApiUrl = '';

    private readonly loader = new Loader<Statistics>(this);
    private timer: number | undefined;

    private get statistics(): Statistics | null {
        return this.loader.data;
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        window.clearTimeout(this.timer);
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        // The patterns and the charts with settings of their own load their own data: a change of those settings
        // alone leaves the statistics as they are
        const withoutPatterns = (state: ActivityState | undefined): string => JSON.stringify(state === undefined
            ? null
            : {
                ...state,
                hours: null,
                year: null,
                split: null,
                timelineInterval: null,
                flowInterval: null,
            });
        const patternOnly = changed.get('state') !== undefined
            && withoutPatterns(changed.get('state')) === withoutPatterns(this.state);
        if ((changed.has('state') && !patternOnly) || changed.has('apiUrl')) {
            void this.load();
        }
    }

    protected override render(): TemplateResult {
        const statistics = this.statistics;

        return html`
            <div class="stream-toolbar is-statistics">
                <forger-activity-filter
                    section="time"
                    .state=${this.state}
                    page-url=${this.statisticsUrl}
                ></forger-activity-filter>
                ${statistics === null ? nothing : html`
                    <p class="stream-window">
                        <strong>${windowLabel(statistics)}</strong>
                        ${this.fromTheStart() ? nothing : html` · ${comparedWith(statistics)}`}
                    </p>
                `}
            </div>
            ${this.board()}
        `;
    }

    /**
     * A window without a first day starts with the first day of Forge: no time before it to compare with.
     */
    private fromTheStart(): boolean {
        return this.state.from === null && this.state.to !== null;
    }

    private board(): TemplateResult {
        if (this.loader.error !== null) {
            return html`<p class="stream-empty">The statistics could not be loaded: ${this.loader.error}</p>`;
        }
        const statistics = this.statistics;
        if (statistics === null) {
            return html`<p class="stream-empty" aria-busy="true">Counting the activity …</p>`;
        }

        const crew = statistics.crews.length > 0;
        const figures = (group: FigureGroup): TemplateResult => html`
            <forger-activity-figures
                group=${group}
                .statistics=${statistics}
                .state=${this.state}
                stream-url=${this.streamUrl}
            ></forger-activity-figures>
        `;

        return html`
            <div class="stream-statistics" aria-busy=${this.loader.loading ? 'true' : 'false'}>
                <h2 class="sr-only">Key figures</h2>
                <div class="stream-dashboard">
                    ${figures('open')}
                    ${figures('counts')}
                    ${figures('waits')}
                    ${(['forge', 'gerrit'] as const).map(source => html`
                        <forger-activity-flow
                            class="stream-widget is-l"
                            .statistics=${statistics}
                            .state=${this.state}
                            source=${source}
                            api-url=${this.apiUrl}
                            statistics-url=${this.statisticsUrl}
                            stream-url=${this.streamUrl}
                        ></forger-activity-flow>
                    `)}
                    <forger-activity-newcomers
                        class="stream-widget is-full"
                        .statistics=${statistics}
                        .state=${this.state}
                        api-url=${this.newcomersApiUrl}
                        stream-url=${this.streamUrl}
                        collapsed
                    ></forger-activity-newcomers>
                    <forger-activity-timeline
                        class="stream-widget is-full"
                        .statistics=${statistics}
                        .state=${this.state}
                        api-url=${this.apiUrl}
                        statistics-url=${this.statisticsUrl}
                        stream-url=${this.streamUrl}
                    ></forger-activity-timeline>
                    ${figures('crew')}
                    <forger-activity-hours
                        class="stream-widget ${crew ? 'is-xl' : 'is-full'}"
                        .state=${this.state}
                        api-url=${this.weekHoursApiUrl}
                        statistics-url=${this.statisticsUrl}
                    ></forger-activity-hours>
                    <forger-activity-year
                        class="stream-widget is-full"
                        .state=${this.state}
                        api-url=${this.daysApiUrl}
                        statistics-url=${this.statisticsUrl}
                        stream-url=${this.streamUrl}
                    ></forger-activity-year>
                    <forger-activity-closed-years
                        api-url=${this.closedYearsApiUrl}
                    ></forger-activity-closed-years>
                </div>
            </div>
        `;
    }

    private async load(): Promise<void> {
        if (this.apiUrl === '') {
            return;
        }
        window.clearTimeout(this.timer);
        const last = await this.loader.load(
            signal => fetchStatistics(this.apiUrl, this.state, 'total', null, signal),
        );
        if (last) {
            this.schedule();
        }
    }

    private schedule(): void {
        window.clearTimeout(this.timer);
        if (this.statistics?.window.open) {
            this.timer = window.setTimeout(() => {
                if (document.visibilityState === 'visible') {
                    void this.load();
                } else {
                    this.schedule();
                }
            }, REFRESH_SECONDS * 1000);
        }
    }
}

const day = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

/**
 * The window as the heading names it: "Nov 15, 2026 – Nov 21, 2026", the last day included.
 */
function windowLabel(statistics: Statistics): string {
    const from = new Date(statistics.window.from);
    const last = new Date(new Date(statistics.window.until).getTime() - 1000);

    return `${day.format(from)} – ${day.format(last)}`;
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-statistics': ActivityStatisticsElement;
    }
}
