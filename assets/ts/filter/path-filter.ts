import { html, type TemplateResult } from 'lit';
import { customElement, property, query, state } from 'lit/decorators.js';
import { LightElement } from '../activity/light-element';

/**
 * The paths of a filter of files, like "File" of the review lists: each active path is a chip, the field adds more.
 * A change of the paths sends the form, as a tick in the other filters does. The paths go to the server as one
 * value, separated by commas, see App\Search\ReviewLists::filePaths().
 *
 * The close icon of the chips comes from a <template> in the element, so it is the one of the template bundle.
 */
@customElement('forger-path-filter')
export class PathFilter extends LightElement {
    /** The name of the value sent, like "filters[file][path]" */
    @property() name = '';
    /** The active paths, separated by commas */
    @property() paths = '';
    @property() placeholder = '';
    /** The id of the field, for its label outside of the element */
    @property({ attribute: 'input-id' }) inputId = '';

    @state() private active: string[] = [];

    @query('input[type="hidden"]') private value!: HTMLInputElement;
    @query('input[type="text"]') private field!: HTMLInputElement;

    private closeIcon: Element | null = null;
    private form: HTMLFormElement | null = null;

    override connectedCallback(): void {
        super.connectedCallback();
        this.active = splitPaths(this.paths);
        this.closeIcon = this.querySelector('template')?.content.firstElementChild ?? null;
        this.form = this.closest('form');
        // "Apply" and the other buttons of the form send what was typed as well
        this.form?.addEventListener('submit', this.takeTyped);
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        this.form?.removeEventListener('submit', this.takeTyped);
    }

    protected override render(): TemplateResult {
        return html`
            <input type="hidden" name=${this.name} .value=${this.active.join(', ')}>
            ${this.active.length > 0 ? html`
                <div class="aggregation-chips">
                    ${this.active.map(path => html`
                        <button type="button" class="chip aggregation-chip" title="Remove ${path} from the filter"
                                @click=${() => this.removePath(path)}>
                            <span class="aggregation-chip-path">${breakable(path)}</span>
                            ${this.closeIcon?.cloneNode(true) ?? '×'}
                        </button>
                    `)}
                </div>
            ` : ''}
            <input type="text" id=${this.inputId} class="form-control form-control-sm" placeholder=${this.placeholder}
                   autocomplete="off" @keydown=${this.onKeydown}>
        `;
    }

    private removePath(path: string): void {
        this.active = this.active.filter(other => other !== path);
        this.value.value = this.active.join(', ');
        this.form?.requestSubmit();
    }

    // Enter adds the paths. Left to the browser, it would press the first button of the form, which is a chip.
    private onKeydown = (event: KeyboardEvent): void => {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        if (this.field.value.trim() !== '') {
            this.form?.requestSubmit();
        }
    };

    // The form data is read right after this event, before Lit renders again: so the value is set by hand. It starts
    // from the value, not from the chips: "Clear" empties the value before the form is sent.
    private takeTyped = (): void => {
        this.active = splitPaths(`${this.value.value},${this.field.value}`);
        this.value.value = this.active.join(', ');
        this.field.value = '';
    };
}

/**
 * A long path breaks after a "/" rather than within a name.
 */
function breakable(path: string): TemplateResult[] {
    return path.split('/').map((part, index, parts) => index < parts.length - 1 ? html`${part}/<wbr>` : html`${part}`);
}

/**
 * Each path once, without a "/" around it, like App\Search\ReviewLists::filePaths().
 */
export function splitPaths(paths: string): string[] {
    const parts = paths.split(',').map(path => path.trim().replace(/^\/+|\/+$/g, ''));

    return [...new Set(parts.filter(path => path !== ''))];
}
