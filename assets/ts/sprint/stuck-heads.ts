/**
 * The heads of the columns of a sprint board stick to the top while their cards scroll by, see _sprint-board.scss.
 * A stuck head gets the class "is-stuck" and with it a light shadow. CSS cannot tell when sticky sticks in every
 * browser, so this compares each head with its column once a frame while the page or the full screen scrolls.
 */
let pending = false;

function update(): void {
    pending = false;
    for (const head of document.querySelectorAll<HTMLElement>('.sprint-board-column-title')) {
        const column = head.parentElement;
        if (column === null) {
            continue;
        }
        // It sticks while it stands at the top of the screen, below the top of its column. At the end of a short
        // column it leaves the screen with it.
        const top = head.getBoundingClientRect().top;
        const stuck = Math.abs(top) < 1 && top - column.getBoundingClientRect().top > 1;
        head.classList.toggle('is-stuck', stuck);
    }
}

function schedule(): void {
    if (!pending) {
        pending = true;
        window.requestAnimationFrame(update);
    }
}

// The scroll of the full screen element does not bubble, so listen while capturing
document.addEventListener('scroll', schedule, {capture: true, passive: true});
document.addEventListener('fullscreenchange', schedule);
document.addEventListener('DOMContentLoaded', schedule);
