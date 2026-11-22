import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { fetchPage } from './api';
import { icon, kind as kindDefinition, sourceLabel } from './definitions';
import { dayKey, dayTitle, formatDay, formatFull, formatTime } from './format';
import { handledByPage, LightElement } from './light-element';
import { row, subjectOf, tags } from './row';
import { defaultState, pageParameters, withQuery, type ActivityState } from './state';
import type { ActivityEvent, ActivityPage } from './types';

const POLL_SECONDS = 60;

/**
 * Sent when the stream loaded a page: the person of the filter and the branches, for the filter.
 */
export const LOADED_EVENT = 'activity-loaded';

/**
 * The events of the activity, the newest first, grouped by the days of the reader. It loads them with the API,
 * more with "Show more". With "compact" it is a plain list of rows of two lines, for a card of the dashboard.
 * With "live" it asks for new events every minute while the tab is visible and the window ends now, and marks
 * them. The events since the last visit are marked too.
 *
 * <forger-activity-stream api-url="/api/v1/activity" stream-url="/activity" limit="6" compact live>
 */
@customElement('forger-activity-stream')
export class ActivityStream extends LightElement {
    @property({ attribute: false }) state: ActivityState = defaultState();
    @property({ attribute: 'api-url' }) apiUrl = '';
    @property({ attribute: 'stream-url' }) streamUrl = '';
    @property({ type: Number }) limit = 50;
    @property({ type: Boolean }) compact = false;
    @property({ type: Boolean }) live = false;
    @property({ attribute: false }) previousVisit: string | null = null;

    @state() private events: ActivityEvent[] = [];
    @state() private page: ActivityPage | null = null;
    @state() private loading = false;
    @state() private error: string | null = null;

    private newIds = new Set<string>();
    private timer: number | undefined;
    private request: AbortController | null = null;

    override connectedCallback(): void {
        super.connectedCallback();
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.clearTimeout(this.timer);
        this.request?.abort();
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        if (changed.has('state') || changed.has('apiUrl') || changed.has('limit')) {
            void this.load(false);
        }
    }

    protected override render(): TemplateResult {
        if (this.error !== null) {
            return html`<p class="stream-empty">The activity could not be loaded: ${this.error}</p>`;
        }
        if (this.page === null) {
            return html`<p class="stream-empty" aria-busy="true">Loading the activity …</p>`;
        }
        const total = this.page.total;
        if (this.compact) {
            return this.events.length === 0
                ? html`<p class="stream-empty">No activity in this time window.</p>`
                : html`<ol class="stream-rows">${this.events.map(event => this.renderRow(event))}</ol>`;
        }

        return html`
            <div class="stream-days" aria-busy=${this.loading ? 'true' : 'false'}>
                ${this.events.length === 0
                    ? html`<p class="stream-empty">No activity in this time window.</p>`
                    : nothing}
                ${groupByDay(this.events).map(group => html`
                    <section class="stream-day" aria-label=${group.title}>
                        <h3 class="stream-day-title">${group.title}</h3>
                        <ol class="stream-events">
                            ${group.events.map(event => this.renderEvent(event))}
                        </ol>
                    </section>
                `)}
            </div>
            ${this.events.length >= Math.min(total, 10000) ? nothing : html`
                <p class="stream-more">
                    <button
                        type="button"
                        class="btn btn-default"
                        ?disabled=${this.loading}
                        @click=${() => this.load(true)}
                    >
                        Show more
                    </button>
                </p>
            `}
        `;
    }

    private renderEvent(event: ActivityEvent): TemplateResult {
        const kind = kindDefinition(event.kind);
        const date = event.date === null ? null : new Date(event.date);
        const isForge = event.source === 'forge';
        const patchSet = event.patchSet !== null && !isForge ? [`Patch set ${event.patchSet}`] : [];
        const details = [...event.summary, ...patchSet];
        const subject = subjectOf(event.title);
        const classes = ['stream-event'];
        if (event.bot) {
            classes.push('is-bot');
        }
        if (this.newIds.has(event.id)) {
            classes.push('is-new');
        }
        if (this.previousVisit !== null && date !== null && date > new Date(this.previousVisit)) {
            classes.push('is-unseen');
        }

        return html`
            <li class=${classes.join(' ')}>
                <span
                    class="stream-event-icon is-${event.source}"
                    title="${sourceLabel(event.source)}: ${kind?.label ?? event.kind}"
                >${kind ? icon(kind.icon) : nothing}</span>
                <span class="stream-event-what">
                    ${event.author === null ? nothing : html`
                        <span class="stream-event-avatar" aria-hidden="true">
                            <img class="avatar" src=${event.author.avatar} width="24" height="24" alt="" loading="lazy">
                        </span>
                    `}
                    ${this.who(event)}
                    ${kind?.verbs[event.source] ?? event.kind}
                    <a href=${event.forgerUrl}>${numberOf(event)}</a>
                </span>
                <a class="stream-event-title" href=${event.forgerUrl} title=${event.title} tabindex="-1">
                    ${tags(subject.tags)}
                    <span class="stream-event-subject">${subject.text}</span>
                </a>
                <span class="stream-event-meta" title=${details.join(' · ') || nothing}>
                    ${event.branch === null ? nothing : html`
                        <span
                            class="tag-label${event.branch !== 'main' ? ' is-backport' : ''}"
                            title="Branch"
                        >${event.branch}</span>
                    `}
                    ${event.bot
                        ? html`<span class="tag-label" title="Written by a tool, not by a person">Bot</span>`
                        : nothing}
                    ${details.length === 0
                        ? nothing
                        : html`<span class="stream-event-details">${details.join(' · ')}</span>`}
                </span>
                ${date === null ? nothing : html`
                    <time
                        class="stream-event-time"
                        datetime=${event.date ?? ''}
                        title=${formatFull(date)}
                    >${formatTime(date)}</time>
                `}
            </li>
        `;
    }

    /**
     * An event of the compact list, see row(): two lines, for a card of the dashboard. A day other than today
     * stands before the time.
     */
    private renderRow(event: ActivityEvent): TemplateResult {
        const kind = kindDefinition(event.kind);
        const date = event.date === null ? null : new Date(event.date);
        const today = date !== null && dayKey(date) === dayKey(new Date());

        return row({
            source: event.source,
            icon: kind?.icon,
            iconLabel: kind?.label ?? event.kind,
            avatar: event.author?.avatar ?? null,
            head: html`
                ${this.who(event)}
                ${kind?.verbs[event.source] ?? event.kind}
                <a href=${event.forgerUrl}>${numberOf(event)}</a>
            `,
            title: event.title,
            url: event.forgerUrl,
            side: date === null ? '' : html`
                <time datetime=${event.date ?? ''} title=${formatFull(date)}>
                    ${today ? '' : `${formatDay(date)}, `}${formatTime(date)}
                </time>
            `,
            classes: this.newIds.has(event.id) ? ['is-new'] : [],
        });
    }

    /**
     * Who did it, as a link to all the person did.
     */
    private who(event: ActivityEvent): TemplateResult {
        const author = event.author;
        if (author === null) {
            return html`<span class="stream-event-who">Somebody</span>`;
        }

        return html`
            <a
                class="stream-event-who"
                href=${this.personUrl(author.key)}
                title="All activity of ${author.name}"
                @click=${(click: MouseEvent) => handledByPage(click, this, { person: author.key, view: 'stream' })}
            >${author.name}</a>
        `;
    }

    private personUrl(key: string): string {
        return withQuery(this.streamUrl, pageParameters({ ...defaultState(), person: key }));
    }

    /**
     * The first page anew, or the next page after the events shown.
     */
    private async load(more: boolean): Promise<void> {
        if (this.apiUrl === '') {
            return;
        }
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        this.loading = true;
        window.clearTimeout(this.timer);
        try {
            const offset = more ? this.events.length : 0;
            const page = await fetchPage(this.apiUrl, this.state, this.limit, offset, request.signal);
            this.newIds.clear();
            this.events = more ? [...this.events, ...page.items] : page.items;
            this.page = page;
            this.error = null;
            this.dispatchEvent(new CustomEvent(LOADED_EVENT, { detail: page, bubbles: true, composed: true }));
        } catch (error) {
            if (!request.signal.aborted) {
                this.error = error instanceof Error ? error.message : String(error);
            }
        } finally {
            if (this.request === request) {
                this.loading = false;
                this.request = null;
                this.schedule();
            }
        }
    }

    private schedule(): void {
        window.clearTimeout(this.timer);
        if (this.live && this.page?.window.open) {
            this.timer = window.setTimeout(() => this.poll(), POLL_SECONDS * 1000);
        }
    }

    /**
     * Puts the new events of the first page on top and marks them. The events shown below stay. It shares the
     * request with load(): a new filter aborts it, so its answer never lands in the list of another filter, and no
     * two polls run at once.
     */
    private async poll(): Promise<void> {
        if (document.visibilityState !== 'visible' || this.request !== null) {
            this.schedule();
            return;
        }
        const request = new AbortController();
        this.request = request;
        window.clearTimeout(this.timer);
        try {
            const page = await fetchPage(this.apiUrl, this.state, this.limit, 0, request.signal);
            const known = new Set(this.events.map(event => event.id));
            const fresh = page.items.filter(event => !known.has(event.id));
            fresh.forEach(event => this.newIds.add(event.id));
            this.events = [...fresh, ...this.events].slice(0, Math.max(this.limit, this.events.length));
            this.page = page;
        } catch {
            // The server is away, or a new filter took over: the stream stays as it is
        } finally {
            if (this.request === request) {
                this.request = null;
                this.schedule();
            }
        }
    }

    private onVisibility = (): void => {
        if (document.visibilityState === 'visible' && this.live && this.page?.window.open) {
            void this.poll();
        }
    };
}

/**
 * The number as the reader knows it: "#111431" for an issue, "change 97855" for a change.
 */
function numberOf(event: ActivityEvent): string {
    return event.source === 'forge' ? `#${event.number}` : `change ${event.number}`;
}

interface DayGroup {
    title: string;
    events: ActivityEvent[];
}

function groupByDay(events: ActivityEvent[]): DayGroup[] {
    const groups = new Map<string, DayGroup>();
    for (const event of events) {
        const date = event.date === null ? null : new Date(event.date);
        const key = date === null ? '' : dayKey(date);
        if (!groups.has(key)) {
            groups.set(key, { title: date === null ? 'Some time' : dayTitle(date), events: [] });
        }
        groups.get(key)?.events.push(event);
    }

    return Array.from(groups.values());
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-stream': ActivityStream;
    }
}
