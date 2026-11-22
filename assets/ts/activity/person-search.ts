import { html, nothing, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { icon } from './definitions';
import { LightElement } from './light-element';
import { withQuery } from './state';

/**
 * A person the search found: the key filters and follows the person, see App\Controller\StreamController::people().
 */
export interface FoundPerson {
    key: string;
    /**
     * Every key a link may name the person by, for the people followed.
     */
    keys?: string[];
    name: string;
    avatar: string | null;
}

/**
 * Sent when the reader picks a person.
 */
export const PICKED_EVENT = 'person-picked';

const MIN_LENGTH = 2;
const DELAY_MS = 200;

let ids = 0;

/**
 * A search field for people with suggestions: the people of the activity whose name or username matches. Arrow
 * keys move through the suggestions, "Enter" picks one. With "submit" it puts the key of the person into the
 * field "person" of its form and sends it, like on My Work. Without it the page takes the event PICKED_EVENT.
 *
 * <forger-person-search search-url="/activity/people" label="Add a person" submit>
 */
@customElement('forger-person-search')
export class PersonSearch extends LightElement {
    @property({ attribute: 'search-url' }) searchUrl = '';
    @property() label = 'Search people';
    @property() placeholder = 'Name or username';
    @property({ type: Boolean }) submit = false;
    /**
     * Shows the key of each person beside the name: the username, or the number of an account without one. The
     * admins tell the accounts of one name apart by it, see App\Controller\PeopleController.
     */
    @property({ type: Boolean, attribute: 'show-keys' }) showKeys = false;

    @state() private term = '';
    @state() private people: FoundPerson[] = [];
    @state() private searched = false;
    @state() private active = -1;
    @state() private loading = false;
    /**
     * The term the suggestions were found for: their names mark it.
     */
    private found = '';

    private readonly listId = `person-search-${++ids}`;
    private timer: number | undefined;
    private request: AbortController | null = null;

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        window.clearTimeout(this.timer);
        this.request?.abort();
    }

    /**
     * Puts the cursor into the field, like when its menu opens.
     */
    override focus(): void {
        this.querySelector('input')?.focus();
    }

    protected override render(): TemplateResult {
        const open = this.term.trim().length >= MIN_LENGTH && this.searched;
        const activeId = this.active < 0 ? '' : `${this.listId}-${this.active}`;

        return html`
            <div class="person-search${this.loading ? ' is-loading' : ''}">
                <label class="sr-only" for="${this.listId}-input">${this.label}</label>
                <span class="person-search-field">
                    <span class="person-search-icon" aria-hidden="true">${icon('actions-search')}</span>
                    <input
                        id="${this.listId}-input"
                        class="form-control form-control-sm person-search-input"
                        type="search"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls=${this.listId}
                        aria-expanded=${open ? 'true' : 'false'}
                        aria-activedescendant=${activeId || nothing}
                        placeholder=${this.placeholder}
                        .value=${this.term}
                        @input=${(event: InputEvent) => this.onInput((event.target as HTMLInputElement).value)}
                        @keydown=${(event: KeyboardEvent) => this.onKey(event)}
                    >
                </span>
                ${open ? this.renderList() : nothing}
            </div>
        `;
    }

    /**
     * The suggestions as the search in the filters of the lists shows them, see assets/js/person-search.js.
     */
    private renderList(): TemplateResult {
        if (this.people.length === 0) {
            return html`<p class="person-search-message" role="status">Nobody found.</p>`;
        }

        return html`
            <ul class="person-search-results" id=${this.listId} role="listbox" aria-label=${this.label}>
                ${this.people.map((person, index) => html`
                    <li
                        id="${this.listId}-${index}"
                        class="person-search-option${index === this.active ? ' is-active' : ''}"
                        role="option"
                        aria-selected=${index === this.active ? 'true' : 'false'}
                        @mousedown=${(event: MouseEvent) => event.preventDefault()}
                        @mousemove=${() => { this.active = index; }}
                        @click=${() => this.pick(person)}
                    >
                        ${person.avatar === null
                            ? html`<span class="person-search-avatar">${initials(person.name)}</span>`
                            : html`<img class="person-search-avatar" src=${person.avatar} alt="" loading="lazy">`}
                        <span class="person-search-name">${highlighted(person.name, this.found)}</span>
                        ${this.showKeys ? html`<span class="person-search-key">${person.key}</span>` : nothing}
                    </li>
                `)}
            </ul>
        `;
    }

    private onInput(term: string): void {
        this.term = term;
        this.active = -1;
        window.clearTimeout(this.timer);
        if (term.trim().length < MIN_LENGTH) {
            this.request?.abort();
            this.people = [];
            this.searched = false;
            return;
        }
        this.timer = window.setTimeout(() => void this.search(term.trim()), DELAY_MS);
    }

    private onKey(event: KeyboardEvent): void {
        const count = this.people.length;
        if (event.key === 'ArrowDown' && count > 0) {
            event.preventDefault();
            this.active = (this.active + 1) % count;
        } else if (event.key === 'ArrowUp' && count > 0) {
            event.preventDefault();
            this.active = (this.active - 1 + count) % count;
        } else if (event.key === 'Enter') {
            // A form around it would send the empty field otherwise
            event.preventDefault();
            const person = this.people[this.active] ?? (count === 1 ? this.people[0] : undefined);
            if (person !== undefined) {
                this.pick(person);
            }
        } else if (event.key === 'Escape' && this.term !== '') {
            // The first "Escape" clears the field, the next one closes a menu around it
            event.stopPropagation();
            this.onInput('');
        }
    }

    private async search(term: string): Promise<void> {
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        this.loading = true;
        try {
            const response = await fetch(withQuery(this.searchUrl, new URLSearchParams({ term })), {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });
            this.people = response.ok ? (await response.json()) as FoundPerson[] : [];
            this.found = term;
            this.searched = true;
            this.active = this.people.length > 0 ? 0 : -1;
        } catch {
            // Aborted for a newer term, or the server is away: the list stays as it is
        } finally {
            if (this.request === request) {
                this.loading = false;
            }
        }
    }

    private pick(person: FoundPerson): void {
        this.dispatchEvent(new CustomEvent<FoundPerson>(PICKED_EVENT, { detail: person, bubbles: true }));
        if (this.submit) {
            const form = this.closest('form');
            const field = form?.querySelector<HTMLInputElement>('input[name="person"]');
            if (form && field) {
                field.value = person.key;
                form.requestSubmit();
            }
        }
        this.onInput('');
    }
}

function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part.charAt(0).toUpperCase()).join('');
}

/**
 * The name with the term marked where it stands in it.
 */
function highlighted(name: string, term: string): TemplateResult | string {
    const start = term === '' ? -1 : name.toLowerCase().indexOf(term.toLowerCase());
    if (start < 0) {
        return name;
    }

    return html`${name.slice(0, start)}<mark>${name.slice(start, start + term.length)}</mark>${
        name.slice(start + term.length)}`;
}

declare global {
    interface HTMLElementTagNameMap {
        'forger-person-search': PersonSearch;
    }
}
