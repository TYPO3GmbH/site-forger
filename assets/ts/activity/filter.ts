import { html, nothing, type TemplateResult } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import './days';
import { closeMenu, menu } from './menu';
import type { PickedDays } from './days';
import { icon, kinds, sources } from './definitions';
import { dayKey, formatNumber } from './format';
import { handledByPage, LightElement, sendChange } from './light-element';
import './person-search';
import type { FoundPerson } from './person-search';
import { applyChange, pageParameters, withQuery, type ActivityChange, type ActivityState } from './state';
import type { ActivityCount, ActivityPerson, Kind, Range } from './types';

const RANGES: { value: Range; label: string; title: string }[] = [
    { value: '7d', label: '7 d', title: 'The last 7 days' },
    { value: '30d', label: '30 d', title: 'The last 30 days' },
    { value: '6m', label: '6 m', title: 'The last 6 months' },
    { value: '1y', label: '1 y', title: 'The last year' },
    { value: 'all', label: 'All', title: 'Since the first year of Forge' },
];

const RESET: ActivityChange = { source: null, kinds: [], branch: null, person: null, following: false, bots: false };

const REVIEW_LOAD: ActivityChange = { kinds: ['voted', 'merged'], source: 'gerrit' };

/**
 * The filter of the activity, in two parts of one bar: "filters" on the left, "time" on the right. Each choice is a
 * link to its view, which <forger-activity> shows in place. The events of tools
 * stay hidden: only the API and the parameter "bots" of the address show them. The menus are
 * <details>: they open without the script, and close when the reader picks or clicks elsewhere.
 */
@customElement('forger-activity-filter')
export class ActivityFilter extends LightElement {
    @property() section: 'time' | 'filters' = 'filters';
    @property({ attribute: false }) state!: ActivityState;
    @property({ attribute: false }) branches: ActivityCount[] = [];
    @property({ attribute: false }) person: ActivityPerson | null = null;
    @property({ attribute: 'page-url' }) pageUrl = '';
    @property({ attribute: 'people-url' }) peopleUrl = '';
    /**
     * The people the logged-in user follows, null for a guest.
     */
    @property({ attribute: false }) followed: FoundPerson[] | null = null;

    protected override render(): TemplateResult {
        return this.section === 'time' ? this.renderTime() : this.renderFilters();
    }

    private renderTime(): TemplateResult {
        const state = this.state;
        const custom = state.from !== null || state.to !== null;
        // The calendar starts with the days of the window picked: the reader moves one end of it. An end left
        // out stays open.
        const from = custom ? state.from ?? '' : rangeStart(state.range);
        const to = custom ? state.to ?? '' : today();

        return html`
            <div class="stream-segmented" role="group" aria-label="Time window">
                ${RANGES.map(range => this.segment(
                    range.label,
                    { range: range.value },
                    !custom && state.range === range.value,
                    range.title,
                ))}
                ${menu(
                    {
                        label: customLabel(state),
                        name: 'Days of the time window',
                        title: 'Pick the days',
                        icon: 'actions-calendar',
                        active: custom,
                        end: true,
                        segment: true,
                        panel: 'stream-daypicker-panel',
                    },
                    html`
                        <forger-activity-days
                            from=${from}
                            to=${to}
                            max=${today()}
                            @days-picked=${this.pickDays}
                        ></forger-activity-days>
                    `,
                )}
            </div>
        `;
    }

    /**
     * The second day picked ends the window: the page shows it and the menu closes.
     */
    private pickDays(event: CustomEvent<PickedDays>): void {
        sendChange(this, { from: event.detail.from || null, to: event.detail.to || null });
        closeMenu(event.currentTarget as HTMLElement);
    }

    private renderFilters(): TemplateResult {
        const state = this.state;
        const filtered = state.source !== null || state.kinds.length > 0 || state.branch !== null
            || state.person !== null || state.following;

        return html`
            <div class="stream-filterbar">
                <div class="stream-segmented" role="group" aria-label="Channel">
                    ${this.segment('All', { source: null }, state.source === null)}
                    ${sources().map(source => this.segment(
                        source.label,
                        { source: source.value },
                        state.source === source.value,
                    ))}
                </div>

                ${menu(
                    {
                        label: kindsLabel(state.kinds),
                        name: 'Kind of event, any of the picked ones',
                        icon: 'actions-filter',
                        active: state.kinds.length > 0,
                    },
                    html`
                        ${this.check('All kinds', { kinds: [] }, state.kinds.length === 0)}
                        ${kinds().map(kind => this.check(
                            html`<span class="stream-menu-icon">${icon(kind.icon)}</span>${kind.label}`,
                            { kinds: toggled(state.kinds, kind.value) },
                            state.kinds.includes(kind.value),
                        ))}
                        <hr class="stream-menu-divider">
                        <span class="stream-menu-label">Shortcuts</span>
                        ${this.choice(
                            html`
                                <span class="stream-menu-text">
                                    Review load
                                    <small>Votes and merges in Gerrit</small>
                                </span>
                            `,
                            REVIEW_LOAD,
                            false,
                        )}
                    `,
                )}

                ${this.branches.length === 0 && state.branch === null ? nothing : menu(
                    {
                        label: state.branch === null ? 'All branches' : `Branch ${state.branch}`,
                        name: 'Branch',
                        active: state.branch !== null,
                    },
                    html`
                        ${this.choice('All branches', { branch: null }, state.branch === null)}
                        ${this.branches.map(branch => this.choice(
                            html`
                                ${branch.label}
                                <span class="stream-menu-count">${formatNumber(branch.count)}</span>
                            `,
                            { branch: branch.key },
                            state.branch === branch.key,
                        ))}
                    `,
                )}

                ${this.renderPeople()}

                ${filtered ? html`
                    <a
                        class="stream-reset"
                        href=${this.href(RESET)}
                        @click=${(event: MouseEvent) => handledByPage(
                            event,
                            this,
                            RESET,
                        )}
                    >Reset</a>
                ` : nothing}
            </div>
        `;
    }

    /**
     * The menu of people: a search, everybody, and for a logged-in user the people followed, all of them at once
     * or one of them.
     */
    private renderPeople(): TemplateResult {
        const state = this.state;
        const followed = this.followed;
        const isPicked = (person: FoundPerson): boolean => state.person !== null
            && (person.key === state.person || (person.keys ?? []).includes(state.person));
        const picked = state.person === null ? null : (followed?.find(isPicked) ?? {
            key: state.person,
            name: this.person?.name ?? state.person,
            avatar: this.person?.avatar ?? null,
        });

        return menu(
            {
                label: picked !== null
                    ? html`<span class="stream-menu-person">${avatar(picked.avatar, 18)}${picked.name}</span>`
                    : (state.following ? 'People I follow' : 'All people'),
                name: 'People',
                icon: picked === null ? 'actions-users' : undefined,
                active: picked !== null || state.following,
                panel: 'stream-people-panel',
                focus: 'forger-person-search input',
            },
            html`
                <forger-person-search
                    search-url=${this.peopleUrl}
                    label="Search people"
                    placeholder="Search by name"
                    @person-picked=${(event: CustomEvent<FoundPerson>) => this.pickPerson(event)}
                ></forger-person-search>
                <hr class="stream-menu-divider">
                ${this.choice(
                    html`<span class="stream-menu-icon">${icon('actions-users')}</span>Everybody`,
                    { person: null, following: false },
                    picked === null && !state.following,
                )}
                ${followed === null || followed.length === 0 ? nothing : this.choice(
                    html`
                        <span class="stream-menu-icon">${icon('actions-eye')}</span>
                        Everyone I follow
                        <span class="stream-menu-count">${followed.length}</span>
                    `,
                    { following: true },
                    state.following,
                )}
                ${picked === null || followed?.some(isPicked) ? nothing : html`
                    <hr class="stream-menu-divider">
                    <span class="stream-menu-label">Picked</span>
                    ${this.choice(html`${avatar(picked.avatar, 20)}${picked.name}`, { person: picked.key }, true)}
                `}
                ${followed === null ? nothing : html`
                    <hr class="stream-menu-divider">
                    <span class="stream-menu-label">You follow</span>
                    ${followed.length === 0
                        ? html`<span class="stream-menu-note">Nobody yet. Pick a person and choose “Follow”.</span>`
                        : followed.map(person => this.choice(
                            html`${avatar(person.avatar, 20)}${person.name}`,
                            { person: person.key },
                            isPicked(person),
                        ))}
                `}
            `,
        );
    }

    private pickPerson(event: CustomEvent<FoundPerson>): void {
        sendChange(this, { person: event.detail.key });
        closeMenu(event.currentTarget as HTMLElement);
    }

    private segment(
        label: string,
        change: ActivityChange,
        active: boolean,
        title: string | null = null,
    ): TemplateResult {
        return html`
            <a
                class="stream-segment${active ? ' is-active' : ''}"
                href=${this.href(change)}
                title=${title ?? nothing}
                aria-current=${active ? 'true' : nothing}
                @click=${(event: MouseEvent) => handledByPage(event, this, change)}
            >${label}</a>
        `;
    }

    private href(change: ActivityChange): string {
        return withQuery(this.pageUrl, pageParameters(applyChange(this.state, change)));
    }

    /**
     * A choice of a menu that closes it: a link the page follows in place.
     */
    private choice(
        label: TemplateResult | string,
        change: ActivityChange,
        active: boolean,
        title: string | null = null,
    ): TemplateResult {
        return html`
            <a
                class="stream-menu-item${active ? ' is-active' : ''}"
                href=${this.href(change)}
                title=${title ?? nothing}
                data-menu-close
                @click=${this.follow(change)}
            >${label}</a>
        `;
    }

    /**
     * A choice with a check box. It leaves the menu open: the reader picks several.
     */
    private check(label: TemplateResult | string, change: ActivityChange, active: boolean): TemplateResult {
        return html`
            <a
                class="stream-menu-item${active ? ' is-active' : ''}"
                href=${this.href(change)}
                role="checkbox"
                aria-checked=${String(active)}
                @click=${this.follow(change)}
            >
                <span class="stream-menu-check" aria-hidden="true"></span>
                ${label}
            </a>
        `;
    }

    private follow(change: ActivityChange): (event: MouseEvent) => void {
        return event => handledByPage(event, this, change);
    }

}

function avatar(url: string | null, size: number): TemplateResult | typeof nothing {
    return url === null
        ? nothing
        : html`<img class="avatar" src=${url} width=${size} height=${size} alt="" loading="lazy">`;
}

function toggled(kinds: Kind[], kind: Kind): Kind[] {
    return kinds.includes(kind) ? kinds.filter(other => other !== kind) : [...kinds, kind];
}

function kindsLabel(picked: Kind[]): string {
    if (picked.length === 0) {
        return 'All kinds';
    }
    const labels = kinds().filter(kind => picked.includes(kind.value)).map(kind => kind.label);

    return labels.length > 2 ? `${labels.length} kinds` : labels.join(', ');
}

const day = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' });

function customLabel(state: ActivityState): string {
    if (state.from === null && state.to === null) {
        return 'Custom';
    }
    const format = (value: string): string => day.format(new Date(value + 'T00:00:00'));
    if (state.from === null || state.to === null) {
        return state.from === null ? `Until ${format(state.to ?? '')}` : `From ${format(state.from)}`;
    }

    return state.from === state.to ? format(state.from) : `${format(state.from)} – ${format(state.to)}`;
}

function today(): string {
    return dayKey(new Date());
}

/**
 * The first day of a range that ends today, as the API counts it: 7 days are today and the 6 days before.
 */
function rangeStart(range: Range): string {
    const start = new Date();
    if (range === '7d' || range === '30d') {
        start.setDate(start.getDate() - (range === '7d' ? 6 : 29));
    } else {
        start.setMonth(start.getMonth() - (range === '6m' ? 6 : 12));
        start.setDate(start.getDate() + 1);
    }

    return dayKey(start);
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-filter': ActivityFilter;
    }
}
