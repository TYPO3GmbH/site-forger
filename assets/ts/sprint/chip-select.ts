/**
 * A list of many values to pick several of, like the categories of a sprint board, see
 * templates/sprint/wizard.html.twig: the values picked as chips with a button that removes each, and a field that
 * suggests the other values while typing. Arrow keys move through the suggestions, "Enter" picks one, "Escape"
 * closes them. The select stays in the form and keeps what is picked; its "change" loads the preview again. Without
 * JavaScript the select itself shows.
 */
const MAX_SUGGESTIONS = 50;

function init(select: HTMLSelectElement, removeTemplate: HTMLTemplateElement | null): void {
    const label = (select.labels[0]?.textContent ?? '').trim().toLowerCase();
    const box = document.createElement('div');
    box.className = 'chip-select';
    const chips = document.createElement('ul');
    chips.className = 'chip-select-chips';
    const field = Object.assign(document.createElement('input'), {
        type: 'search',
        className: 'form-control form-control-sm chip-select-field',
        placeholder: 'Search and add …',
        autocomplete: 'off',
    });
    field.setAttribute('role', 'combobox');
    field.setAttribute('aria-expanded', 'false');
    field.setAttribute('aria-label', `Add ${label}`);
    // The label of the select leads to the field that stands in for it
    field.id = `${select.id}-search`;
    const menu = document.createElement('ul');
    menu.className = 'chip-select-menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    box.append(chips, field, menu);
    select.after(box);
    select.hidden = true;
    select.labels[0]?.setAttribute('for', field.id);

    let suggestions: HTMLOptionElement[] = [];
    let active = -1;

    const changed = (): void => {
        select.dispatchEvent(new Event('change', { bubbles: true }));
        renderChips();
    };

    function renderChips(): void {
        chips.replaceChildren(...Array.from(select.selectedOptions).map((option) => {
            const chip = document.createElement('li');
            chip.className = 'chip-select-chip';
            chip.append(option.value);
            const remove = removeTemplate?.content.firstElementChild?.cloneNode(true) as HTMLElement | undefined;
            if (remove !== undefined) {
                remove.setAttribute('aria-label', `Remove ${option.value}`);
                remove.addEventListener('click', () => {
                    option.selected = false;
                    changed();
                    field.focus();
                });
                chip.append(remove);
            }

            return chip;
        }));
    }

    function renderMenu(): void {
        const term = field.value.trim().toLowerCase();
        suggestions = Array.from(select.options)
            .filter((option) => !option.selected && (term === '' || option.text.toLowerCase().includes(term)))
            .slice(0, MAX_SUGGESTIONS);
        active = suggestions.length > 0 ? 0 : -1;
        menu.replaceChildren(...suggestions.map((option, index) => {
            const item = document.createElement('li');
            item.className = 'chip-select-option' + (index === active ? ' is-active' : '');
            item.setAttribute('role', 'option');
            item.textContent = option.text;
            item.addEventListener('mousedown', (event) => {
                // Before the field loses the focus and closes the menu
                event.preventDefault();
                pick(option);
            });

            return item;
        }));
        const open = document.activeElement === field && suggestions.length > 0;
        menu.hidden = !open;
        field.setAttribute('aria-expanded', String(open));
    }

    function pick(option: HTMLOptionElement): void {
        option.selected = true;
        field.value = '';
        changed();
        renderMenu();
    }

    function move(step: number): void {
        if (suggestions.length === 0) {
            return;
        }
        active = (active + step + suggestions.length) % suggestions.length;
        Array.from(menu.children).forEach((item, index) => item.classList.toggle('is-active', index === active));
        menu.children[active]?.scrollIntoView({ block: 'nearest' });
    }

    field.addEventListener('input', renderMenu);
    field.addEventListener('focus', renderMenu);
    field.addEventListener('blur', () => {
        menu.hidden = true;
        field.setAttribute('aria-expanded', 'false');
    });
    field.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            move(event.key === 'ArrowDown' ? 1 : -1);
        } else if (event.key === 'Enter') {
            // Picks a value instead of saving the board
            event.preventDefault();
            const option = suggestions[active];
            if (option !== undefined) {
                pick(option);
            }
        } else if (event.key === 'Escape') {
            menu.hidden = true;
        } else if (event.key === 'Backspace' && field.value === '' && select.selectedOptions.length > 0) {
            select.selectedOptions[select.selectedOptions.length - 1].selected = false;
            changed();
        }
    });
    renderChips();
}

document.addEventListener('DOMContentLoaded', () => {
    const template = document.querySelector<HTMLTemplateElement>('[data-chip-select-remove]');
    document.querySelectorAll<HTMLSelectElement>('select[data-filter-select]')
        .forEach((select) => init(select, template));
});

// A module of its own, so its functions do not meet those of other scripts
export {};
