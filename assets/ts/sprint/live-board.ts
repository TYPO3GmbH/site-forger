/**
 * A sprint board with "data-board-refresh" loads its parts again every minute from that address, see
 * SprintController::columns(): the summary, the bar and the columns. Each new part replaces the one with the same
 * "data-board-part". Forger imports Forge every five minutes, so a minute keeps a board on a shared screen current.
 * A hidden tab does not ask; it asks at once when it shows again. A "Show all" the viewer opened stays open.
 */
const INTERVAL = 60_000;

/**
 * The columns whose "Show all" the viewer opened, by the id of their head. A column keeps it across loads, also
 * while it has too few cards for the toggle, until the viewer closes it.
 */
const opened = new Set<string>();

function columnOf(details: Element): string {
    return details.closest('.sprint-board-column')?.getAttribute('aria-labelledby') ?? '';
}

function reopen(root: Element): void {
    for (const details of root.querySelectorAll('.sprint-board-column details.show-more')) {
        if (opened.has(columnOf(details))) {
            details.setAttribute('open', '');
        }
    }
}

function showUpdated(root: Element): void {
    const label = root.querySelector<HTMLElement>('[data-board-updated]');
    if (label === null) {
        return;
    }
    const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    label.textContent = `Live · ${time}`;
    label.title = `Loads the issues again every minute, last at ${time}`;
    label.hidden = false;
}

async function refresh(root: HTMLElement): Promise<void> {
    const url = root.dataset.boardRefresh ?? '';
    let html: string;
    try {
        const response = await fetch(url, {headers: {Accept: 'text/html'}, cache: 'no-store'});
        if (!response.ok) {
            return;
        }
        html = await response.text();
    } catch {
        // Offline for a moment: the next minute tries again
        return;
    }
    const parts = new DOMParser().parseFromString(html, 'text/html').querySelectorAll('[data-board-part]');
    for (const part of parts) {
        root.querySelector(`[data-board-part="${part.getAttribute('data-board-part')}"]`)?.replaceWith(part);
    }
    reopen(root);
    showUpdated(root);
    // The heads of the new columns: whether they stick, see stuck-heads.ts
    document.dispatchEvent(new Event('scroll'));
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector<HTMLElement>('[data-board-refresh]');
    if (root === null) {
        return;
    }
    showUpdated(root);
    // "toggle" does not bubble, so listen while capturing. A load sets "open" again, which toggles, too: no harm.
    root.addEventListener('toggle', (event) => {
        const details = event.target;
        if (!(details instanceof HTMLDetailsElement) || !details.classList.contains('show-more')) {
            return;
        }
        if (details.open) {
            opened.add(columnOf(details));
        } else {
            opened.delete(columnOf(details));
        }
    }, true);
    let last = Date.now();
    const tick = (): void => {
        if (document.visibilityState !== 'visible') {
            return;
        }
        last = Date.now();
        void refresh(root);
    };
    window.setInterval(tick, INTERVAL);
    document.addEventListener('visibilitychange', () => {
        if (Date.now() - last >= INTERVAL) {
            tick();
        }
    });
});
