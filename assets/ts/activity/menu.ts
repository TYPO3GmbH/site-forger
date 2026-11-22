import { html, nothing, type TemplateResult } from 'lit';
import { icon } from './definitions';

/**
 * How a menu looks and where it opens.
 */
export interface MenuOptions {
    /**
     * What the button says: the choice made.
     */
    label: TemplateResult | string;
    /**
     * What the panel holds, for a screen reader.
     */
    name: string;
    title?: string;
    /**
     * An icon of the template bundle before the label.
     */
    icon?: string;
    /**
     * Whether the menu has a choice other than its default: the button stands out.
     */
    active?: boolean;
    /**
     * The panel opens to the left of the button, for a menu at the right edge.
     */
    end?: boolean;
    /**
     * A part of a switch, like "Custom" beside the ranges, in place of a button of its own.
     */
    segment?: boolean;
    /**
     * A class of the panel, for a panel with its own layout.
     */
    panel?: string;
}

/**
 * A menu of the activity: a button with the choice made, and a panel that opens below it. All menus of the
 * filter and of the charts are this one, so they look and behave alike. It is a <details>: it opens without the
 * script. <forger-activity-menu> closes it on a click elsewhere, on "Escape" and after a click on a choice with
 * "data-menu-close". A choice without it leaves the menu open, to pick several. See ".stream-menu" in
 * assets/css/module/_stream.scss.
 */
export function menu(options: MenuOptions, content: TemplateResult): TemplateResult {
    const button = options.segment ? 'stream-segment' : 'stream-menu-button';

    return html`
        <forger-activity-menu>
            <details class="stream-menu${options.end ? ' stream-menu-end' : ''}">
                <summary class="${button}${options.active ? ' is-active' : ''}" title=${options.title ?? nothing}>
                    ${options.icon === undefined ? nothing : icon(options.icon)}
                    <span>${options.label}</span>
                    ${options.segment ? nothing : icon('actions-chevron-down')}
                </summary>
                <div
                    class="stream-menu-panel${options.panel === undefined ? '' : ` ${options.panel}`}"
                    role="group"
                    aria-label=${options.name}
                >${content}</div>
            </details>
        </forger-activity-menu>
    `;
}

/**
 * Closes the menu a choice or a part of its panel is in, like after a form in it was sent.
 */
export function closeMenu(inside: Element): void {
    inside.closest('details.stream-menu')?.removeAttribute('open');
}

/**
 * The behavior of a menu. It renders nothing: its content is the <details> of menu().
 */
class ActivityMenu extends HTMLElement {
    connectedCallback(): void {
        document.addEventListener('click', this.onClick);
        this.addEventListener('keydown', this.onKey);
    }

    disconnectedCallback(): void {
        document.removeEventListener('click', this.onClick);
        this.removeEventListener('keydown', this.onKey);
    }

    private get details(): HTMLDetailsElement | null {
        return this.querySelector('details');
    }

    private onClick = (event: MouseEvent): void => {
        // By the path of the click: a part that drew itself anew has the element clicked no longer
        const path = event.composedPath();
        const choice = path.some(target => target instanceof Element && target.hasAttribute('data-menu-close'));
        if (!path.includes(this) || choice) {
            this.details?.removeAttribute('open');
        }
    };

    private onKey = (event: KeyboardEvent): void => {
        const details = this.details;
        if (event.key === 'Escape' && details?.open) {
            details.removeAttribute('open');
            details.querySelector('summary')?.focus();
        }
    };
}

customElements.define('forger-activity-menu', ActivityMenu);

declare global {
    interface HTMLElementTagNameMap {
        'forger-activity-menu': ActivityMenu;
    }
}
