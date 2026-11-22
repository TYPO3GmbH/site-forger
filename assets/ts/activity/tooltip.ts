/**
 * The tooltip of the charts: the text of "data-tip" of the bar, the hour or the day under the pointer. A line break
 * in the text starts a new line. One element
 * for the page, fixed to the window: no scrolling part of a chart cuts it off, and it moves to the other side of
 * the pointer at the edges of the window. See ".stream-tooltip" in assets/css/module/_stream.scss.
 */
const OFFSET = 12;

let tooltip: HTMLElement | null = null;

function element(): HTMLElement {
    if (tooltip === null) {
        tooltip = document.createElement('div');
        tooltip.className = 'stream-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.hidden = true;
        document.body.append(tooltip);
    }

    return tooltip;
}

function place(tip: HTMLElement, x: number, y: number): void {
    const { width, height } = tip.getBoundingClientRect();
    const left = x + OFFSET + width > window.innerWidth ? x - OFFSET - width : x + OFFSET;
    const top = y - OFFSET - height < 0 ? y + OFFSET : y - OFFSET - height;
    tip.style.left = `${Math.max(4, left)}px`;
    tip.style.top = `${Math.max(4, top)}px`;
}

function show(event: PointerEvent): void {
    const target = (event.target as Element | null)?.closest<HTMLElement>('[data-tip]');
    const tip = element();
    if (!target) {
        tip.hidden = true;
        return;
    }
    tip.textContent = target.dataset.tip ?? '';
    tip.hidden = false;
    place(tip, event.clientX, event.clientY);
}

function hide(): void {
    if (tooltip !== null) {
        tooltip.hidden = true;
    }
}

let installed = false;

/**
 * Shows the tooltips of the elements with "data-tip" while the pointer is over them. One set of listeners serves
 * the whole page: the charts sit inside each other, and each with its own would handle a move twice.
 */
export function withTooltips(): void {
    if (installed) {
        return;
    }
    installed = true;
    document.addEventListener('pointermove', show, { passive: true });
    document.documentElement.addEventListener('pointerleave', hide);
    window.addEventListener('scroll', hide, { passive: true });
}
