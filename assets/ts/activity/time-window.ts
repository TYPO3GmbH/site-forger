import { html, type TemplateResult } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import './filter';
import { CHANGE_EVENT, LightElement } from './light-element';
import { applyChange, defaultState, withQuery, type ActivityChange, type ActivityState } from './state';
import type { Range } from './types';

const RANGES: Range[] = ['7d', '30d', '6m', '1y'];

/**
 * The time window of the statistics on a page the server renders, like the leaderboard: the ranges and the days
 * in the calendar. A change loads the page again with the new window, and keeps the parameters of "keep", like the
 * order. The server gives the window it shows, so a window it did not take shows as the one it took.
 */
@customElement('forger-time-window')
export class TimeWindow extends LightElement {
    @property({ attribute: 'page-url' }) pageUrl = '';
    @property() range = '';
    @property() from = '';
    @property() to = '';
    /**
     * The parameters of the page to keep, as a query string like "sort=reviews".
     */
    @property() keep = '';

    override connectedCallback(): void {
        super.connectedCallback();
        this.addEventListener(CHANGE_EVENT, this.onChange as EventListener);
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        this.removeEventListener(CHANGE_EVENT, this.onChange as EventListener);
    }

    protected override render(): TemplateResult {
        return html`
            <forger-activity-filter section="time" .state=${this.state()} page-url=${this.pageUrl}>
            </forger-activity-filter>
        `;
    }

    private state(): ActivityState {
        const range = RANGES.find(value => value === this.range);

        return {
            ...defaultState('statistics'),
            range: range ?? defaultState().range,
            from: range === undefined && this.from !== '' ? this.from : null,
            to: range === undefined && this.to !== '' ? this.to : null,
        };
    }

    private onChange = (event: CustomEvent<ActivityChange>): void => {
        event.preventDefault();
        window.location.assign(this.url(applyChange(this.state(), event.detail)));
    };

    /**
     * The window always by name: the page may take another one by default than the statistics.
     */
    private url(state: ActivityState): string {
        const parameters = new URLSearchParams(this.keep);
        if (state.from !== null || state.to !== null) {
            parameters.set('from', state.from ?? state.to ?? '');
            parameters.set('to', state.to ?? state.from ?? '');
        } else {
            parameters.set('range', state.range);
        }

        return withQuery(this.pageUrl, parameters);
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-time-window': TimeWindow;
    }
}
