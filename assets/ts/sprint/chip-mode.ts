/**
 * A filter of a sprint board that either picks values or leaves them out, never both, like its trackers and its
 * statuses, see templates/sprint/_board_form_issue_filters.html.twig: one row of chips shows, a switch says
 * whether the board shows only these values or hides them, and a sentence below says what that means. The switch
 * moves the chips picked
 * from one row to the other, so the form never sends both. "data-chip-mode-noun" names one value, like "tracker".
 * Without JavaScript both rows show.
 */
type Mode = 'only' | 'except';

function boxes(group: Element | null): HTMLInputElement[] {
    return Array.from(group?.querySelectorAll<HTMLInputElement>('input[type="checkbox"]') ?? []);
}

function init(container: HTMLElement): void {
    const groups: Record<Mode, HTMLElement | null> = {
        only: container.querySelector<HTMLElement>('[data-chip-mode-group="only"]'),
        except: container.querySelector<HTMLElement>('[data-chip-mode-group="except"]'),
    };
    const buttons = Array.from(container.querySelectorAll<HTMLButtonElement>('[data-chip-mode-set]'));
    const switcher = buttons[0]?.parentElement ?? null;
    if (groups.only === null || groups.except === null || switcher === null) {
        return;
    }
    switcher.hidden = false;
    container.classList.add('has-switch');

    const summary = container.querySelector<HTMLElement>('[data-chip-mode-summary]');
    const names = (items: string[]): string => items.length < 2
        ? items.join('')
        : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
    // What the chips mean together, in a sentence below them
    const describe = (): void => {
        if (summary === null) {
            return;
        }
        const mode: Mode = container.dataset.chipMode === 'except' ? 'except' : 'only';
        const picked = boxes(groups[mode]).filter((box) => box.checked).map((box) => box.value);
        const noun = container.dataset.chipModeNoun ?? 'value';
        if (picked.length === 0) {
            const hint = mode === 'only' ? 'show only it' : (container.dataset.chipModeHide ?? 'hide it');
            summary.textContent = `The board shows every ${noun}. Click one to ${hint}.`;
        } else {
            summary.textContent = mode === 'only'
                ? `The board shows only ${names(picked)}.`
                : `The board shows every ${noun} except ${names(picked)}.`;
        }
    };
    container.addEventListener('change', describe);

    const show = (mode: Mode): void => {
        container.dataset.chipMode = mode;
        groups.only!.hidden = mode !== 'only';
        groups.except!.hidden = mode !== 'except';
        for (const button of buttons) {
            const active = button.dataset.chipModeSet === mode;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
        }
        describe();
    };

    const move = (mode: Mode): void => {
        const from = boxes(groups[mode === 'only' ? 'except' : 'only']);
        const to = boxes(groups[mode]);
        const picked = new Set(from.filter((box) => box.checked).map((box) => box.value));
        // With chips in both rows, as a board saved without JavaScript may have, those of the row shown stay
        if (picked.size > 0) {
            for (const box of to) {
                box.checked = picked.has(box.value);
            }
        }
        for (const box of from) {
            box.checked = false;
        }
        show(mode);
        // The preview follows the filters, see sprint-wizard.js
        to[0]?.dispatchEvent(new Event('change', { bubbles: true }));
    };

    for (const button of buttons) {
        button.addEventListener('click', () => {
            const mode = button.dataset.chipModeSet as Mode;
            if (mode !== container.dataset.chipMode) {
                move(mode);
            }
        });
    }
    // One row only from the start: the other gives its chips to the one shown
    const mode: Mode = container.dataset.chipMode === 'except' ? 'except' : 'only';
    for (const box of boxes(groups[mode === 'only' ? 'except' : 'only'])) {
        box.checked = false;
    }
    show(mode);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('[data-chip-mode]').forEach(init);
});

// A module of its own, so its functions do not meet those of other scripts
export {};
