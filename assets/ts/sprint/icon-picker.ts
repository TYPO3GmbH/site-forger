/**
 * The picker of the icon of a category, see templates/sprintcategory/form.html.twig: radio buttons with the icons.
 * Typing in the search shows only the icons whose name contains the words. The head shows the picked icon and its
 * name. Without JavaScript the search stays hidden and every icon shows.
 */
function init(picker: HTMLElement): void {
    const search = picker.querySelector<HTMLInputElement>('[data-icon-picker-search]');
    const empty = picker.querySelector<HTMLElement>('[data-icon-picker-empty]');
    const preview = picker.querySelector<HTMLElement>('[data-icon-picker-preview]');
    const name = picker.querySelector<HTMLElement>('[data-icon-picker-name]');
    const options = Array.from(picker.querySelectorAll<HTMLElement>('[data-icon-picker-option]'));
    if (search === null) {
        return;
    }
    search.hidden = false;

    search.addEventListener('input', () => {
        const words = search.value.toLowerCase().split(/\s+/).filter((word) => word !== '');
        let shown = 0;
        for (const option of options) {
            const matches = words.every((word) => (option.dataset.iconPickerOption ?? '').includes(word));
            option.hidden = !matches;
            shown += matches ? 1 : 0;
        }
        if (empty !== null) {
            empty.hidden = shown > 0;
        }
    });

    picker.addEventListener('change', (event) => {
        const radio = event.target;
        if (!(radio instanceof HTMLInputElement) || preview === null || name === null) {
            return;
        }
        const icon = radio.closest('label')?.querySelector('.icon');
        preview.replaceChildren(...(icon ? [icon.cloneNode(true)] : []));
        name.textContent = radio.value;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('[data-icon-picker]').forEach(init);
});
