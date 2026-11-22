import { html, nothing, type PropertyValues, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { icon } from './definitions';
import { LightElement } from './light-element';
import { withQuery } from './state';

/**
 * Sent when the user followed a person or stopped it: the page loads the people followed anew.
 */
export const FOLLOW_EVENT = 'follow-changed';

interface FollowState {
    person: string;
    following: boolean;
    /**
     * The person is the user: nobody follows oneself.
     */
    self: boolean;
}

/**
 * The button to follow the person of the filter, for a logged-in user. It asks the state with the key of the
 * person and sets it with a form post, see App\Controller\FollowController. My Work shows the people followed.
 *
 * <forger-follow-button url="/my/following/state" token="…" person="forge:36415" name="Jane Doe">
 *
 * The header of the person in the activity shows it, see Activity::renderHeader().
 */
@customElement('forger-follow-button')
export class FollowButton extends LightElement {
    @property() url = '';
    @property() token = '';
    @property() person = '';
    @property() name = '';

    @state() private following: boolean | null = null;
    @state() private busy = false;
    @state() private error: string | null = null;

    private request: AbortController | null = null;

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        this.request?.abort();
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        if (changed.has('person') || changed.has('url')) {
            void this.load();
        }
    }

    protected override render(): TemplateResult | typeof nothing {
        if (this.following === null) {
            return nothing;
        }
        const label = this.following ? 'Following' : 'Follow';
        const title = this.following
            ? `Stop following ${this.name || 'this person'}`
            : `Follow ${this.name || 'this person'} on My Work`;

        return html`
            <button
                type="button"
                class="stream-follow${this.following ? ' is-following' : ''}"
                title=${title}
                aria-pressed=${this.following ? 'true' : 'false'}
                ?disabled=${this.busy}
                @click=${() => this.toggle()}
            >${icon(this.following ? 'actions-check' : 'actions-eye')}<span>${label}</span></button>
            ${this.error === null ? nothing : html`<span class="stream-follow-error" role="alert">${this.error}</span>`}
        `;
    }

    private async load(): Promise<void> {
        this.request?.abort();
        this.following = null;
        this.error = null;
        if (this.url === '' || this.person === '') {
            return;
        }
        const request = new AbortController();
        this.request = request;
        try {
            const query = new URLSearchParams({ person: this.person });
            const response = await fetch(withQuery(this.url, query), {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });
            // A person the server does not know, and the user, cannot be followed: the button stays away
            if (response.ok) {
                const answer = (await response.json()) as FollowState;
                this.following = answer.self ? null : answer.following;
            }
        } catch {
            // Aborted for another person, or the server is away: no button
        }
    }

    private async toggle(): Promise<void> {
        if (this.following === null || this.busy) {
            return;
        }
        this.busy = true;
        const body = new URLSearchParams({
            _token: this.token,
            person: this.person,
            following: this.following ? '0' : '1',
        });
        try {
            const response = await fetch(this.url, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body,
            });
            const data = await response.json().catch(() => null);
            if (!response.ok) {
                throw new Error(data && typeof data.error === 'string' ? data.error : 'The request failed.');
            }
            this.following = (data as FollowState).following;
            this.error = null;
            this.dispatchEvent(new CustomEvent(FOLLOW_EVENT, { bubbles: true }));
        } catch (error) {
            this.error = error instanceof Error ? error.message : 'The request failed.';
        } finally {
            this.busy = false;
        }
    }
}
