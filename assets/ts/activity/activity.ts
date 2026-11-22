import { html, nothing, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import './filter';
import { CHANGE_EVENT, LightElement } from './light-element';
import {
    applyChange,
    pageParameters,
    stateFromParameters,
    withQuery,
    type ActivityChange,
    type ActivityState,
} from './state';
import './statistics';
import './stream';
import { LOADED_EVENT } from './stream';
import type { ActivityCount, ActivityPage, ActivityPerson } from './types';
import { previousVisit } from './visit';

/**
 * The settings of single charts of the statistics. The address keeps them, so a link shows the same charts.
 */
const CHART_SETTINGS: (keyof ActivityState)[] = ['split', 'timelineInterval', 'flowInterval', 'hours', 'year'];

/**
 * The frame of a page of the activity: the filter and one view, the stream or the statistics. It keeps the filter in
 * the address and changes it in place: the filter and the view send CHANGE_EVENT, the history goes back and forth.
 * A link to the other view leaves the page. See templates/stream/_frame.html.twig.
 */
@customElement('forger-activity')
export class Activity extends LightElement {
    @property() view: ActivityState['view'] = 'stream';
    @property({ attribute: 'stream-url' }) streamUrl = '';
    @property({ attribute: 'statistics-url' }) statisticsUrl = '';
    @property({ attribute: 'api-url' }) apiUrl = '';
    @property({ attribute: 'statistics-api-url' }) statisticsApiUrl = '';
    @property({ attribute: 'week-hours-api-url' }) weekHoursApiUrl = '';
    @property({ attribute: 'days-api-url' }) daysApiUrl = '';
    @property({ attribute: 'newcomers-api-url' }) newcomersApiUrl = '';
    @property({ attribute: 'closed-years-api-url' }) closedYearsApiUrl = '';

    @state() private state!: ActivityState;
    @state() private branches: ActivityCount[] = [];
    @state() private person: ActivityPerson | null = null;

    private readonly visit = previousVisit();

    override connectedCallback(): void {
        super.connectedCallback();
        this.state = stateFromParameters(new URLSearchParams(window.location.search), this.view);
        this.addEventListener(CHANGE_EVENT, this.onChange as EventListener);
        this.addEventListener(LOADED_EVENT, this.onLoaded as EventListener);
        window.addEventListener('popstate', this.onPopState);
        // The address as the page reads it: without the parameters it ignores
        window.history.replaceState({ activity: true }, '', this.address());
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        window.removeEventListener('popstate', this.onPopState);
    }

    protected override render(): TemplateResult {
        const view = this.view;
        const pageUrl = this.pageUrl();

        return html`
            ${view === 'statistics' ? nothing : html`
                <div class="stream-toolbar">
                    <forger-activity-filter
                        section="filters"
                        .state=${this.state}
                        .branches=${this.branches}
                        .person=${this.person}
                        page-url=${pageUrl}
                    ></forger-activity-filter>
                    <forger-activity-filter
                        section="time"
                        .state=${this.state}
                        page-url=${pageUrl}
                    ></forger-activity-filter>
                </div>
            `}
            ${view === 'statistics' ? html`
                <forger-activity-statistics
                    .state=${this.state}
                    api-url=${this.statisticsApiUrl}
                    statistics-url=${this.statisticsUrl}
                    stream-url=${this.streamUrl}
                    week-hours-api-url=${this.weekHoursApiUrl}
                    days-api-url=${this.daysApiUrl}
                    newcomers-api-url=${this.newcomersApiUrl}
                    closed-years-api-url=${this.closedYearsApiUrl}
                ></forger-activity-statistics>
            ` : html`
                <forger-activity-stream
                    .state=${this.state}
                    .previousVisit=${this.visit}
                    api-url=${this.apiUrl}
                    stream-url=${this.streamUrl}
                    live
                ></forger-activity-stream>
            `}
        `;
    }

    private pageUrl(): string {
        return this.view === 'statistics' ? this.statisticsUrl : this.streamUrl;
    }

    /**
     * A change to the other view is no business of this page: the link leaves it.
     */
    private onChange = (event: CustomEvent<ActivityChange>): void => {
        if (event.detail.view !== undefined && event.detail.view !== this.view) {
            return;
        }
        event.preventDefault();
        this.state = applyChange(this.state, event.detail);
        // A setting of a chart is no step of the history: "back" leaves the page, it does not undo each switch
        if (Object.keys(event.detail).every(key => CHART_SETTINGS.includes(key as keyof ActivityState))) {
            window.history.replaceState({ activity: true }, '', this.address());
        } else {
            window.history.pushState({ activity: true }, '', this.address());
        }
    };

    private address(): string {
        return withQuery(this.pageUrl(), pageParameters(this.state)) + window.location.hash;
    }

    private onLoaded = (event: CustomEvent<ActivityPage>): void => {
        this.branches = event.detail.branches;
        this.person = event.detail.person;
    };

    private onPopState = (): void => {
        this.state = stateFromParameters(new URLSearchParams(window.location.search), this.view);
    };
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity': Activity;
    }
}
