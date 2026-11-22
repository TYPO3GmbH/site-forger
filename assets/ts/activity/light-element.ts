import { LitElement } from 'lit';

/**
 * An element without a shadow root: it renders into itself, so the stylesheet of the page styles it, see
 * assets/css/module/_stream.scss.
 */
export class LightElement extends LitElement {
    protected override createRenderRoot(): HTMLElement {
        return this;
    }
}

/**
 * The event the elements send when the reader changes the filter or the view. <forger-activity> takes it and
 * calls preventDefault(): the element then knows that the page handled the link, see handledByPage().
 */
export const CHANGE_EVENT = 'activity-change';

export function sendChange(element: HTMLElement, change: object): boolean {
    const event = new CustomEvent(CHANGE_EVENT, { detail: change, bubbles: true, composed: true, cancelable: true });
    element.dispatchEvent(event);

    return event.defaultPrevented;
}

/**
 * Follows a link of the filter in place when the page takes the change, as a plain link otherwise, like on the
 * dashboard. A click with a modifier key opens it as usual.
 */
export function handledByPage(event: MouseEvent, element: HTMLElement, change: object): void {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }
    if (sendChange(element, change)) {
        event.preventDefault();
    }
}
