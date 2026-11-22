import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { fetchExchange } from './api';
import { icon } from './definitions';
import { badge, change, formatDuration, sparkline, valueLine } from './figures';
import { formatNumber } from './format';
import { LightElement } from './light-element';
import { Loader } from './loader';
import { defaultState, filterParameters, pageParameters, withQuery, type ActivityState } from './state';
import type { ActivityExchange, ActivityOwnWait, ActivityPartner, ActivityTrend } from './types';

/**
 * Below this share a wait of the user is no faster or slower than the one of all changes.
 */
const NOTICEABLE_SHARE = 0.1;

/**
 * Between these shares of reviews given to reviews got the user gives about as much as the user gets.
 */
const BALANCED_LOW = 0.8;
const BALANCED_HIGH = 1.25;

/**
 * The reviews between the user and the others, on the board of one user: how long the changes of the user wait,
 * the reviews given and got, and the closest partners. It loads them with the API for the window of the page. The
 * element has no box of its own: its cards are widgets of the dashboard grid around it, see ".stream-dashboard".
 */
@customElement('forger-activity-exchange')
export class ActivityExchangeElement extends LightElement {
    @property({ attribute: false }) state!: ActivityState;
    @property({ attribute: 'api-url' }) apiUrl = '';
    @property({ attribute: 'stream-url' }) streamUrl = '';
    /**
     * Where the user finds the own changes, see App\Controller\MyWorkController::changes().
     */
    @property({ attribute: 'changes-url' }) changesUrl = '';

    private readonly loader = new Loader<ActivityExchange>(this);
    private requested = '';

    protected override willUpdate(changed: PropertyValues<this>): void {
        if (!changed.has('state') && !changed.has('apiUrl')) {
            return;
        }
        const key = filterParameters(this.state).toString() + '|' + (this.state.own ?? '');
        if (key !== this.requested && this.apiUrl !== '') {
            this.requested = key;
            void this.loader.load(signal => fetchExchange(this.apiUrl, this.state, signal));
        }
    }

    protected override render(): TemplateResult {
        const exchange = this.loader.data;
        if (exchange === null) {
            const text = this.loader.error === null
                ? 'Counting your reviews …'
                : `Your reviews could not be loaded: ${this.loader.error}`;

            return html`
                <p class="stream-empty stream-widget is-full" aria-busy=${this.loader.error === null}>${text}</p>
            `;
        }

        return html`
            ${this.waits(exchange)}
            ${this.giveAndTake(exchange)}
            ${this.partners(exchange)}
        `;
    }

    /**
     * How long the changes of the user waited, beside all changes: where a ping or a smaller change could help.
     */
    private waits(exchange: ActivityExchange): TemplateResult {
        return html`
            <section class="stream-chart stream-widget is-l" aria-label="Your changes">
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">Your changes</h3>
                        <p class="stream-chart-note">How long they waited, the median beside all changes</p>
                    </div>
                    ${this.changesUrl === '' ? nothing : html`
                        <a class="stream-chart-link" href=${this.changesUrl}>Open changes</a>
                    `}
                </header>
                <dl class="stream-own-waits">${exchange.waits.map(wait => this.wait(wait))}</dl>
            </section>
        `;
    }

    private wait(wait: ActivityOwnWait): TemplateResult {
        const all = wait.allMedian === null ? 'no change' : formatDuration(wait.allMedian);
        if (wait.median === null) {
            return html`
                <div class="stream-own-wait" title=${wait.description}>
                    <dt class="stream-figure-label">${wait.label}</dt>
                    <dd class="stream-own-wait-note">None of your changes in the window · all changes ${all}</dd>
                </div>
            `;
        }

        return html`
            <div class="stream-own-wait" title=${wait.description}>
                <dt class="stream-figure-label">${wait.label}</dt>
                <dd>${valueLine(formatDuration(wait.median), comparison(wait.median, wait.allMedian))}</dd>
                <dd class="stream-own-wait-note">
                    Median of ${formatNumber(wait.count)} ${wait.count === 1 ? 'change' : 'changes'} · all changes
                    ${all}
                </dd>
            </div>
        `;
    }

    /**
     * The changes of others the user reviewed, and the changes of the user others reviewed. A change needs several
     * votes and comments, so the changes count, not the votes.
     */
    private giveAndTake(exchange: ActivityExchange): TemplateResult {
        const { given, received } = exchange;

        return html`
            <section class="stream-chart stream-widget is-l" aria-label="Give and take">
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">Give and take</h3>
                        <p class="stream-chart-note">Changes reviewed: by you, and of yours by others</p>
                    </div>
                </header>
                <div class="stream-exchange">
                    ${this.side('Given', 'Changes of others you reviewed', given, exchange.givenReviews, exchange)}
                    ${this.side(
                        'Received',
                        'Changes of yours others reviewed',
                        received,
                        exchange.receivedReviews,
                        exchange,
                    )}
                </div>
                ${balance(given.count, received.count)}
            </section>
        `;
    }

    private side(
        label: string,
        note: string,
        trend: ActivityTrend,
        reviews: number,
        exchange: ActivityExchange,
    ): TemplateResult {
        const before = `${formatNumber(trend.previous)} in the ${exchange.window.days} days before`;

        return html`
            <div class="stream-figure" title=${note}>
                <span class="stream-figure-label">${label}</span>
                ${valueLine(formatNumber(trend.count), change(trend, before))}
                <span class="stream-own-wait-note">
                    ${trend.count === 1 ? 'change' : 'changes'} · ${formatNumber(reviews)} votes and comments
                </span>
                ${sparkline(trend.values, 'gerrit', { soFar: exchange.window.open })}
            </div>
        `;
    }

    /**
     * Who the user works with: whose changes the user reviews, and who reviews the changes of the user. A name opens
     * the activity of the person.
     */
    private partners(exchange: ActivityExchange): TemplateResult {
        return html`
            <section class="stream-chart stream-widget is-full" aria-label="Who you work with">
                <header class="stream-chart-header">
                    <div>
                        <h3 class="stream-chart-title">Who you work with</h3>
                        <p class="stream-chart-note">The people with the most changes reviewed between you</p>
                    </div>
                </header>
                <div class="stream-partners">
                    ${this.partnerList('You reviewed', exchange.reviewed, 'Nobody else’s changes yet')}
                    ${this.partnerList('Reviewed you', exchange.reviewers, 'No reviews on your changes yet')}
                </div>
            </section>
        `;
    }

    private partnerList(title: string, partners: ActivityPartner[], empty: string): TemplateResult {
        const most = Math.max(1, ...partners.map(partner => partner.changes));

        return html`
            <div class="stream-partner-list">
                <h4 class="stream-figure-label">${title}</h4>
                ${partners.length === 0 ? html`<p class="stream-own-wait-note">${empty}</p>` : html`
                    <ol class="stream-partner-rows">
                        ${partners.map(partner => html`
                            <li class="stream-partner">
                                <a class="stream-partner-name" href=${this.personUrl(partner)}
                                    title="The activity of ${partner.person.name}">
                                    <img class="avatar" src=${partner.person.avatar} width="24" height="24" alt="">
                                    <span>${partner.person.name}</span>
                                </a>
                                <span class="stream-partner-track" aria-hidden="true">
                                    <span style="width: ${(partner.changes / most) * 100}%"></span>
                                </span>
                                <span
                                    class="stream-partner-count"
                                    title="${formatNumber(partner.reviews)} votes and comments"
                                >${formatNumber(partner.changes)}</span>
                            </li>
                        `)}
                    </ol>
                `}
            </div>
        `;
    }

    private personUrl(partner: ActivityPartner): string {
        return withQuery(this.streamUrl, pageParameters({ ...defaultState(), person: partner.person.key }));
    }
}

/**
 * The wait of the user beside the one of all changes: "2× faster" or "2× slower". Less is better.
 */
function comparison(median: number, all: number | null): TemplateResult | typeof nothing {
    if (all === null || all === 0 || median === 0) {
        return nothing;
    }
    const share = median / all;
    if (Math.abs(1 - share) < NOTICEABLE_SHARE) {
        return badge('as all', 0, 'less', 'About as long as all changes');
    }
    const faster = share < 1;
    const times = faster ? all / median : share;
    const text = `${times >= 10 ? Math.round(times) : times.toFixed(1)}× ${faster ? 'faster' : 'slower'}`;

    return badge(text, faster ? -1 : 1, 'less', `All changes: ${formatDuration(all)}`);
}

/**
 * Whether the user reviews as many changes of others as others review of the user: the review works when everybody
 * reviews about as much as they ask for. Getting much more than giving is the side to change.
 */
function balance(given: number, received: number): TemplateResult {
    if (given === 0 && received === 0) {
        return html`<p class="stream-own-wait-note">No reviews between you and others in the window.</p>`;
    }
    const ratio = received === 0 ? Infinity : given / received;
    if (ratio >= BALANCED_LOW && ratio <= BALANCED_HIGH) {
        return balanceLine(
            'better',
            'actions-check',
            'Balanced',
            'You review about as many changes as others review of yours.',
        );
    }
    if (ratio > 1) {
        const times = received === 0 ? '' : ` ${ratio.toFixed(1)}×`;

        return balanceLine(
            'better',
            'actions-check',
            'Giving',
            `You review${times} as many changes as others review of yours. Thank you!`,
        );
    }

    return balanceLine(
        'worse',
        'actions-exclamation-triangle',
        'Out of balance',
        `Others review ${(1 / ratio).toFixed(1)}× as many of your changes as you review of theirs. `
            + 'Reviews on the changes of others even it out.',
    );
}

/**
 * The balance as a badge in the colors of the key figures, without an arrow: it is a state, not a change.
 */
function balanceLine(tone: 'better' | 'worse', iconName: string, label: string, text: string): TemplateResult {
    return html`
        <p class="stream-balance">
            <span class="stream-figure-delta is-${tone}">${icon(iconName)} ${label}</span>
            <span class="stream-own-wait-note">${text}</span>
        </p>
    `;
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-exchange': ActivityExchangeElement;
    }
}
