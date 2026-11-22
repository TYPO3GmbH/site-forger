import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { fetchNewcomers } from './api';
import { icon, kind, newcomerState } from './definitions';
import { change } from './figures';
import { formatDay, formatNumber } from './format';
import { handledByPage, LightElement } from './light-element';
import { Loader } from './loader';
import { row } from './row';
import { defaultState, filterParameters, pageParameters, withQuery, type ActivityState } from './state';
import type { ActivityNewcomer, ActivityNewcomers, ActivityStatistics, Kind, Milestone, Range } from './types';

const DAY = 86_400_000;

/**
 * The rows of a collapsed list: three lines of two on a wide board.
 */
const COLLAPSED_ROWS = 6;

/**
 * The kind of event a first is, for its icon.
 */
const KINDS: Record<Milestone, Kind> = {
    'first-issue': 'created',
    'first-change': 'created',
    'first-merge': 'merged',
};

/**
 * "New contributors": the firsts of new people, their first issue, their first change and the first change of
 * theirs that was merged, and where they stand, so a mentor sees who could use help. Each first is a row of its
 * own. Those who wait come first, then the newest. On the page of statistics it follows the filter and loads
 * anew with the page. On its own, like on the dashboard, it loads once for its "range" and shows "limit" of
 * them.
 *
 * <forger-activity-newcomers api-url="/api/v1/activity/newcomers" range="30d" limit="6" compact>
 */
@customElement('forger-activity-newcomers')
export class ActivityNewcomersElement extends LightElement {
    /**
     * The statistics of the page: their window tells when to load anew. None on its own.
     */
    @property({ attribute: false }) statistics: ActivityStatistics | null = null;
    @property({ attribute: false }) state: ActivityState | null = null;
    @property({ attribute: 'api-url' }) apiUrl = '';
    @property({ attribute: 'stream-url' }) streamUrl = '';
    @property() range: Range = '30d';
    @property({ type: Number }) limit = 0;
    /**
     * Without its own card and totals: a list for a section of the dashboard.
     */
    @property({ type: Boolean }) compact = false;
    /**
     * Shows the newest few and a button for the rest: a card of the dashboard stays as high as its neighbour.
     */
    @property({ type: Boolean }) collapsed = false;

    @state() private expanded = false;

    private readonly loader = new Loader<ActivityNewcomers>(this);
    private requested = '';

    private get data(): ActivityNewcomers | null {
        return this.loader.data;
    }

    private get error(): string | null {
        return this.loader.error;
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        super.willUpdate(changed);
        const key = JSON.stringify([
            filterParameters(this.filter()).toString(),
            this.statistics?.window.until ?? '',
        ]);
        if (key !== this.requested && this.apiUrl !== '') {
            this.requested = key;
            void this.load();
        }
    }

    protected override render(): TemplateResult {
        const people = this.people();
        const list = html`
            ${this.error !== null ? html`<p class="stream-empty">${this.error}</p>` : nothing}
            ${this.data === null || people.length > 0 ? nothing : html`
                <p class="stream-empty">Nobody reported a first issue or pushed a first change in this time.</p>
            `}
            ${people.length === 0 ? nothing : html`
                <ul class="stream-rows">${this.shown(people).map(person => this.row(person))}</ul>
                ${this.moreButton(people)}
            `}
        `;
        if (this.compact) {
            return list;
        }

        return html`
            <section class="stream-chart" aria-label="New contributors">
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">New contributors</h3>
                        <p class="stream-chart-note">
                            First issues, first changes and first merges. Who waits comes first.
                        </p>
                    </div>
                </header>
                ${this.totals()}
                ${list}
            </section>
        `;
    }

    /**
     * The filter the list follows: the one of the page, else the range of its own.
     */
    private filter(): ActivityState {
        return this.state ?? { ...defaultState('statistics'), range: this.range };
    }

    /**
     * The newcomers of the channel of the filter, at most "limit". Those who could use help come first. The API
     * sends the newest first, and the sort keeps that order among them and among the others.
     */
    private people(): ActivityNewcomer[] {
        const source = this.filter().source;
        const people = (this.data?.people ?? [])
            .filter(person => source === null || person.source === source)
            .sort((one, other) => Number(other.needsHelp) - Number(one.needsHelp));

        return this.limit > 0 ? people.slice(0, this.limit) : people;
    }

    /**
     * The rows in sight: the newest few while collapsed.
     */
    private shown(people: ActivityNewcomer[]): ActivityNewcomer[] {
        return this.collapsed && !this.expanded ? people.slice(0, COLLAPSED_ROWS) : people;
    }

    private moreButton(people: ActivityNewcomer[]): TemplateResult | typeof nothing {
        if (!this.collapsed || people.length <= COLLAPSED_ROWS) {
            return nothing;
        }

        return html`
            <button
                type="button"
                class="show-more stream-newcomers-more"
                aria-expanded=${this.expanded ? 'true' : 'false'}
                @click=${() => { this.expanded = !this.expanded; }}
            >
                ${this.expanded ? 'Show less' : `Show ${formatNumber(people.length - COLLAPSED_ROWS)} more`}
                ${icon('actions-chevron-down')}
            </button>
        `;
    }

    private totals(): TemplateResult | typeof nothing {
        const data = this.data;
        if (data === null) {
            return nothing;
        }
        const source = this.filter().source;
        const count = (key: Milestone): number => data.milestones.find(one => one.key === key)?.count ?? 0;
        const total = source === null
            ? data.count
            : source === 'forge' ? count('first-issue') : count('first-change');
        const people = this.people();
        const waiting = people.filter(person => person.needsHelp).length;
        const helpLabel = people.length < total
            ? `Of the ${people.length} newest, could use help now`
            : 'Could use help now';
        const previous = { count: data.count, previous: data.previous, values: [], better: data.better };

        return html`
            <dl class="stream-flow-totals">
                <div>
                    <dt>New contributions</dt>
                    <dd>
                        ${formatNumber(total)}
                        ${source !== null
                            ? nothing
                            : change(previous, `${formatNumber(data.previous)} in the time before`)}
                    </dd>
                </div>
                ${source !== null ? nothing : html`
                    <div>
                        <dt>First issues</dt>
                        <dd>${formatNumber(count('first-issue'))}</dd>
                    </div>
                    <div>
                        <dt>First changes</dt>
                        <dd>${formatNumber(count('first-change'))}</dd>
                    </div>
                `}
                ${source === 'forge' ? nothing : html`
                    <div>
                        <dt>First merges</dt>
                        <dd>${formatNumber(count('first-merge'))}</dd>
                    </div>
                `}
                <div>
                    <dt>${helpLabel}</dt>
                    <dd>${formatNumber(waiting)}</dd>
                </div>
            </dl>
        `;
    }

    private row(newcomer: ActivityNewcomer): TemplateResult {
        const state = newcomerState(newcomer.state);
        const days = Math.floor((Date.now() - Date.parse(newcomer.date)) / DAY);
        const since = newcomer.needsHelp && days > 0 ? ` · open for ${days} ${days === 1 ? 'day' : 'days'}` : '';
        const person = newcomer.person;
        const activityOf = { person: person.key, view: 'stream' };
        const what = {
            'first-issue': `First issue #${newcomer.number}`,
            'first-change': `First change ${newcomer.number}`,
            'first-merge': `First merge: change ${newcomer.number}`,
        }[newcomer.milestone];
        const mergedBy = newcomer.mergedBy === null ? '' : ` · merged by ${newcomer.mergedBy.name}`;
        const event = kind(KINDS[newcomer.milestone]);

        return row({
            source: newcomer.source,
            icon: event?.icon,
            iconLabel: what,
            avatar: person.avatar,
            head: html`
                <a
                    class="stream-event-who"
                    href=${this.personUrl(person.key)}
                    title="All activity of ${person.name}"
                    @click=${(event: MouseEvent) => handledByPage(event, this, activityOf)}
                >${person.name}</a>
                <a href=${newcomer.forgerUrl}>${what}</a>
            `,
            title: newcomer.title,
            url: newcomer.forgerUrl,
            side: html`
                <span class="status-pill status-pill-${state?.severity ?? 'ready'}">${newcomer.stateLabel}</span>
            `,
            note: `${formatDay(new Date(newcomer.date))}${since}${mergedBy}`,
            classes: newcomer.needsHelp ? ['needs-help'] : [],
        });
    }

    private personUrl(key: string): string {
        return withQuery(this.streamUrl, pageParameters({ ...defaultState(), person: key }));
    }

    private load(): Promise<boolean> {
        return this.loader.load(signal => fetchNewcomers(this.apiUrl, this.filter(), signal));
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-newcomers': ActivityNewcomersElement;
    }
}
