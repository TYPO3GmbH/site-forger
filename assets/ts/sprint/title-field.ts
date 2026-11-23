/**
 * The name of a sprint board in the title of its form, see templates/sprint/wizard.html.twig: the pen turns the
 * title into its field. "Enter" or leaving the field shows the new name as the title again, "Escape" the one
 * before. The field saves with the form, not by itself. An empty field stays open: a board needs a name.
 */
function init(container: HTMLElement): void {
    const text = container.querySelector<HTMLElement>('[data-title-field-text]');
    const pen = container.querySelector<HTMLElement>('[data-title-field-edit]');
    const input = container.querySelector<HTMLInputElement>('[data-title-field-input]');
    if (text === null || pen === null || input === null) {
        return;
    }
    let before = input.value;

    const show = (editing: boolean): void => {
        text.hidden = editing;
        pen.hidden = editing;
        input.hidden = !editing;
    };
    const close = (): void => {
        const value = input.value.trim();
        if (value === '') {
            return;
        }
        input.value = value;
        text.textContent = value;
        show(false);
    };

    pen.addEventListener('click', () => {
        before = input.value;
        show(true);
        input.focus();
        input.select();
    });
    input.addEventListener('blur', close);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            // Shows the name, does not save the board yet
            event.preventDefault();
            close();
            pen.focus();
        } else if (event.key === 'Escape' && before !== '') {
            input.value = before;
            close();
            pen.focus();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('[data-title-field]').forEach(init);
});

// A module of its own, so its functions do not meet those of other scripts
export {};
