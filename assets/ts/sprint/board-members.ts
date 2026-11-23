import { PICKED_EVENT, type FoundPerson } from '../activity/person-search';

/**
 * The people a sprint board is shared with, in its form, see templates/sprint/_board_form_access.html.twig, and the
 * owners a board of reviews filters for, see _board_form_reviews.html.twig: a list
 * with their names instead of the field of the usernames. The search of people adds one, "×" removes one. The field
 * keeps their keys, separated by commas, and saves with the board. Without JavaScript the field itself shows.
 */
function init(container: HTMLElement): void {
    const value = container.querySelector<HTMLInputElement>('[data-board-members-value]');
    const list = container.querySelector<HTMLElement>('[data-board-members-list]');
    const empty = container.querySelector<HTMLElement>('[data-board-members-empty]');
    const search = container.querySelector<HTMLElement>('[data-board-members-search]');
    const template = container.querySelector<HTMLTemplateElement>('[data-board-members-remove-template]');
    if (value === null || list === null || search === null) {
        return;
    }
    value.hidden = true;
    container.querySelector('.form-text')?.setAttribute('hidden', '');
    search.hidden = false;

    const keys = (): string[] => Array.from(list.querySelectorAll<HTMLElement>('[data-key]'))
        .map((item) => item.dataset.key ?? '');
    const update = (): void => {
        value.value = keys().join(', ');
        // A preview that follows the field loads again, see sprint-wizard.js
        value.dispatchEvent(new Event('change', { bubbles: true }));
        if (empty !== null) {
            empty.hidden = keys().length > 0;
        }
    };

    list.addEventListener('click', (event) => {
        const button = (event.target as Element | null)?.closest('[data-board-members-remove]');
        if (button) {
            button.closest('[data-key]')?.remove();
            update();
        }
    });

    search.addEventListener(PICKED_EVENT, (event) => {
        const person = (event as CustomEvent<FoundPerson>).detail;
        if (keys().includes(person.key)) {
            return;
        }
        const item = document.createElement('li');
        item.className = 'board-members-item';
        item.dataset.key = person.key;
        const name = document.createElement('span');
        name.className = 'user';
        if (person.avatar) {
            const avatar = Object.assign(document.createElement('img'), {
                className: 'avatar', src: person.avatar, width: 24, height: 24, alt: '',
            });
            const wrap = document.createElement('span');
            wrap.className = 'user-avatar';
            wrap.append(avatar);
            name.append(wrap);
        }
        const label = document.createElement('span');
        label.className = 'user-name';
        label.textContent = person.name;
        name.append(label);
        // The button to remove it, from the template the page rendered
        const remove = template?.content.firstElementChild?.cloneNode(true);
        item.append(name, ...(remove ? [remove] : []));
        list.append(item);
        update();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('[data-board-members]').forEach(init);
});
