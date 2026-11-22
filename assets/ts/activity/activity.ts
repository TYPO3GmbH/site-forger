import { html, nothing, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { icon, profileUrl } from './definitions';
import './filter';
import { FOLLOW_EVENT } from './follow';
import { formatNumber } from './format';
import type { FoundPerson } from './person-search';
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
    @property({ attribute: 'exchange-api-url' }) exchangeApiUrl = '';
    @property({ attribute: 'changes-url' }) changesUrl = '';
    @property({ attribute: 'people-url' }) peopleUrl = '';
    /**
     * Where a logged-in user follows a person, and the people the user follows. Empty for a guest, see
     * App\Controller\FollowController.
     */
    @property({ attribute: 'follow-url' }) followUrl = '';
    @property({ attribute: 'follow-token' }) followToken = '';
    @property({ attribute: 'followed-url' }) followedUrl = '';
    @property({ attribute: 'manage-url' }) manageUrl = '';
    /**
     * The statistics of one user, see App\Controller\MyWorkController::statistics(): the key the links to the stream
     * filter by, and the keys of all accounts of the user, separated by commas. Empty for the board of all.
     */
    @property({ attribute: 'own-person' }) ownPerson = '';
    @property({ attribute: 'own-people' }) ownPeople = '';

    @state() private state!: ActivityState;
    @state() private branches: ActivityCount[] = [];
    @state() private person: ActivityPerson | null = null;
    @state() private total: number | null = null;
    /**
     * The people the user follows, null for a guest and until they are loaded.
     */
    @state() private followed: FoundPerson[] | null = null;

    private readonly visit = previousVisit();

    override connectedCallback(): void {
        super.connectedCallback();
        this.state = this.stateOfAddress();
        this.addEventListener(CHANGE_EVENT, this.onChange as EventListener);
        this.addEventListener(LOADED_EVENT, this.onLoaded as EventListener);
        this.addEventListener(FOLLOW_EVENT, this.loadFollowed);
        void this.loadFollowed();
        window.addEventListener('popstate', this.onPopState);
        // The address as the page reads it: without the parameters it ignores
        window.history.replaceState({ activity: true }, '', this.address());
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        window.removeEventListener('popstate', this.onPopState);
        this.removeEventListener(FOLLOW_EVENT, this.loadFollowed);
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
                        .followed=${this.followed}
                        page-url=${pageUrl}
                        people-url=${this.peopleUrl}
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
                    exchange-api-url=${this.exchangeApiUrl}
                    changes-url=${this.changesUrl}
                ></forger-activity-statistics>
            ` : html`
                ${this.renderHeader()}
                ${this.renderStream()}
            `}
        `;
    }

    /**
     * Who the stream is of, when it is of one person or of the people followed: the place to follow a person.
     */
    private renderHeader(): TemplateResult | typeof nothing {
        const state = this.state;
        const events = this.total === null ? '' : `${formatNumber(this.total)} ${this.total === 1 ? 'event' : 'events'}`
            + ' in this time window';
        if (state.following && this.followed !== null) {
            return html`
                <section class="stream-person-header" aria-label="People you follow">
                    <span class="stream-person-header-avatars" aria-hidden="true">
                        ${this.followed.slice(0, 5).map(person => person.avatar === null ? nothing : html`
                            <img class="avatar" src=${person.avatar} width="40" height="40" alt="">
                        `)}
                    </span>
                    <span class="stream-person-header-text">
                        <h2 class="stream-person-header-name">People you follow</h2>
                        <span class="stream-person-header-meta">
                            ${this.followed.length} ${this.followed.length === 1 ? 'person' : 'people'}
                            ${events === '' ? nothing : html` · ${events}`}
                        </span>
                    </span>
                    <a class="stream-follow is-following" href=${this.manageUrl}>Manage</a>
                </section>
            `;
        }
        if (state.person === null) {
            return nothing;
        }
        const person = this.person;

        return html`
            <section class="stream-person-header" aria-label="Activity of ${person?.name ?? state.person}">
                ${person === null ? nothing : html`
                    <img class="avatar" src=${person.avatar} width="48" height="48" alt="">
                `}
                <span class="stream-person-header-text">
                    <h2 class="stream-person-header-name">${person?.name ?? state.person}</h2>
                    <span class="stream-person-header-meta">
                        ${this.renderProfile(person)}${events}
                    </span>
                </span>
                ${this.followUrl === '' ? nothing : html`
                    <forger-follow-button
                        url=${this.followUrl}
                        token=${this.followToken}
                        person=${person?.key ?? state.person}
                        name=${person?.name ?? ''}
                    ></forger-follow-button>
                `}
            </section>
        `;
    }

    /**
     * The username, linked to the public profile on my.typo3.org.
     */
    private renderProfile(person: ActivityPerson | null): TemplateResult | typeof nothing {
        const url = person?.username ? profileUrl(person.username) : null;
        if (person === null || url === null) {
            return nothing;
        }

        return html`
            <a class="stream-person-header-profile" href=${url} target="_blank" rel="noopener"
                title="The profile of ${person.name} on my.typo3.org">${person.username} ${icon('actions-window-open')}</a>
            ·
        `;
    }

    private renderStream(): TemplateResult {
        let people = '';
        if (this.state.following) {
            if (this.followed === null) {
                return html`<p class="stream-empty" aria-busy="true">Loading the people you follow …</p>`;
            }
            if (this.followed.length === 0) {
                return html`
                    <p class="stream-empty">
                        You follow nobody yet. Pick a person in “All people” and choose “Follow”.
                    </p>
                `;
            }
            people = this.followed.map(person => person.key).join(',');
        }

        return html`
            <forger-activity-stream
                .state=${this.state}
                .previousVisit=${this.visit}
                api-url=${this.apiUrl}
                stream-url=${this.streamUrl}
                people=${people}
                live
            ></forger-activity-stream>
        `;
    }

    private loadFollowed = async (): Promise<void> => {
        if (this.followedUrl === '') {
            return;
        }
        try {
            const response = await fetch(this.followedUrl, { headers: { Accept: 'application/json' } });
            if (response.ok) {
                this.followed = (await response.json()) as FoundPerson[];
            }
        } catch {
            // The server is away: the menu shows no people followed
        }
    };

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
        this.total = event.detail.total;
    };

    private onPopState = (): void => {
        this.state = this.stateOfAddress();
    };

    private stateOfAddress(): ActivityState {
        const state = stateFromParameters(new URLSearchParams(window.location.search), this.view);
        if (this.ownPeople === '') {
            return state;
        }

        return { ...state, person: this.ownPerson || null, own: this.ownPeople };
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity': Activity;
    }
}
