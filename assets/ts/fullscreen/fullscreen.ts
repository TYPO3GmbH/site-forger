/**
 * A button with "data-fullscreen" shows the element its value selects in full screen, like the columns of a sprint
 * board on a screen of a contribution night. A second click or Escape ends it. Its title and its element with
 * "data-fullscreen-label" follow the state. Without the Fullscreen API of the browser the button stays hidden.
 */
const LABEL_ENTER = 'Full screen';
const LABEL_EXIT = 'Exit full screen';

function buttons(): HTMLButtonElement[] {
    return Array.from(document.querySelectorAll<HTMLButtonElement>('button[data-fullscreen]'));
}

function showState(): void {
    for (const button of buttons()) {
        const target = document.querySelector(button.dataset.fullscreen ?? '');
        const active = target !== null && document.fullscreenElement === target;
        button.setAttribute('aria-pressed', String(active));
        const text = active ? LABEL_EXIT : LABEL_ENTER;
        button.title = text;
        const label = button.querySelector('[data-fullscreen-label]');
        if (label !== null) {
            label.textContent = text;
        }
    }
}

async function toggle(button: HTMLButtonElement): Promise<void> {
    const target = document.querySelector(button.dataset.fullscreen ?? '');
    if (target === null) {
        return;
    }
    if (document.fullscreenElement === target) {
        await document.exitFullscreen();
        return;
    }
    await target.requestFullscreen();
}

document.addEventListener('DOMContentLoaded', () => {
    if (!document.fullscreenEnabled) {
        return;
    }
    for (const button of buttons()) {
        button.hidden = false;
    }
});

document.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest<HTMLButtonElement>('button[data-fullscreen]');
    if (button) {
        void toggle(button);
    }
});

document.addEventListener('fullscreenchange', showState);
