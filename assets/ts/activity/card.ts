import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { property, state } from 'lit/decorators.js';
import { fetchStatistics } from './api';
import { handledByPage, LightElement } from './light-element';
import { Loader } from './loader';
import {
    applyChange,
    filterParameters,
    pageParameters,
    withQuery,
    type ActivityChange,
    type ActivityState,
} from './state';
import type { ActivityStatistics, Interval, Split } from './types';

/**
 * Chart or table, in the same place, see ".overview-view-toggle" in _dashboard.scss.
 */
export function tableSwitch(label: string, table: boolean, show: (table: boolean) => void): TemplateResult {
    return html`
        <span class="overview-view-toggle" role="group" aria-label="View of ${label}">
            <button
                type="button"
                class=${table ? '' : 'is-active'}
                aria-pressed=${table ? 'false' : 'true'}
                @click=${() => show(false)}
            >Chart</button>
            <button
                type="button"
                class=${table ? 'is-active' : ''}
                aria-pressed=${table ? 'true' : 'false'}
                @click=${() => show(true)}
            >Table</button>
        </span>
    `;
}

const INTERVAL_LABELS: Record<Interval, string> = {
    hour: 'Hour',
    day: 'Day',
    week: 'Week',
    month: 'Month',
};

/**
 * A chart of the statistics with its own settings in its head: the length of a bar, a view, chart or table. With
 * the interval and the split of the page it shows the statistics of the page. With others it asks the API itself,
 * and asks again when the page loads anew.
 */
export abstract class StatisticsCard extends LightElement {
    /**
     * The statistics of the page.
     */
    @property({ attribute: false }) statistics!: ActivityStatistics;
    @property({ attribute: false }) state!: ActivityState;
    @property({ attribute: 'api-url' }) apiUrl = '';
    @property({ attribute: 'statistics-url' }) statisticsUrl = '';
    @property({ attribute: 'stream-url' }) streamUrl = '';

    @state() protected showTable = false;
    /**
     * The statistics of the card with its own interval or split. A failed request leaves what it showed: the
     * statistics of the page say when the API fails.
     */
    private readonly own = new Loader<ActivityStatistics>(this);
    private requested = '';

    /**
     * The interval the reader picked for the card, null for the one by the length of the window.
     */
    protected abstract interval(): Interval | null;

    /**
     * The key of the interval in the state, for the links of the switch.
     */
    protected abstract intervalChange(interval: Interval | null): ActivityChange;

    protected split(): Split {
        return 'total';
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        super.willUpdate(changed);
        if (this.interval() === null && this.split() === 'total') {
            this.own.reset();
            this.requested = '';
            return;
        }
        // The end of the window of the page changes when the page loads anew: then the card does, too
        const key = JSON.stringify([
            filterParameters(this.state).toString(),
            this.interval(),
            this.split(),
            this.statistics.window.until,
        ]);
        if (key !== this.requested) {
            this.requested = key;
            void this.load();
        }
    }

    /**
     * The statistics the card shows: its own when it has them, else those of the page.
     */
    protected data(): ActivityStatistics {
        return this.own.data ?? this.statistics;
    }

    protected busy(): 'true' | 'false' {
        return this.own.loading ? 'true' : 'false';
    }

    protected href(change: ActivityChange): string {
        return withQuery(this.statisticsUrl, pageParameters(applyChange(this.state, change)));
    }

    /**
     * The length of a bar, as the window of the page allows it.
     */
    protected intervalSwitch(): TemplateResult | typeof nothing {
        const intervals = this.statistics.window.intervals;
        if (intervals.length < 2) {
            return nothing;
        }
        const current = this.data().window.interval;

        return html`
            <div class="stream-segmented" role="group" aria-label="Length of a bar">
                ${intervals.map(value => {
                    // The interval by the length of the window needs no parameter
                    const change = this.intervalChange(value === this.statistics.window.interval ? null : value);

                    return html`
                        <a
                            class="stream-segment${current === value ? ' is-active' : ''}"
                            href=${this.href(change)}
                            aria-current=${current === value ? 'true' : nothing}
                            @click=${(event: MouseEvent) => handledByPage(event, this, change)}
                        >${INTERVAL_LABELS[value]}</a>
                    `;
                })}
            </div>
        `;
    }

    protected tableSwitch(label: string): TemplateResult {
        return tableSwitch(label, this.showTable, table => { this.showTable = table; });
    }

    private async load(): Promise<void> {
        if (this.apiUrl !== '') {
            await this.own.load(
                signal => fetchStatistics(this.apiUrl, this.state, this.split(), this.interval(), signal),
            );
        }
    }
}
