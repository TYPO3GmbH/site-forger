/**
 * Asks in the dialog of Forger before something is deleted, see templates/_partials/confirm_dialog.html.twig. Never
 * the dialog of the browser.
 *
 * - A form with "data-confirm" (the question) is sent only after "Delete".
 * - A link with "data-confirm" and "data-confirm-post" (an address) sends a POST there after "Delete", with the
 *   token of "data-confirm-token". Without JavaScript the link opens its page, which asks itself.
 *
 * "data-confirm-title" and "data-confirm-button" name the dialog and its button.
 */
const DIALOG_ID = 'confirm-dialog';

function ask(source: HTMLElement): Promise<boolean> {
    const dialog = document.getElementById(DIALOG_ID);
    if (!(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function') {
        // No dialog on the page: better not delete than ask with the dialog of the browser
        return Promise.resolve(false);
    }
    const set = (selector: string, text: string): void => {
        const element = dialog.querySelector(selector);
        if (element !== null) {
            element.textContent = text;
        }
    };
    set('[data-confirm-title]', source.dataset.confirmTitle ?? 'Delete it?');
    set('[data-confirm-text]', source.dataset.confirm ?? '');
    set('[data-confirm-button]', source.dataset.confirmButton ?? 'Delete');
    dialog.returnValue = '';
    dialog.showModal();

    return new Promise((resolve) => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
    });
}

function post(link: HTMLElement): void {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = link.dataset.confirmPost ?? '';
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = link.dataset.confirmToken ?? '';
    form.append(token);
    document.body.append(form);
    form.submit();
}

// Capturing, so the question comes before any other handler of the form
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.confirm === undefined) {
        return;
    }
    if (form.dataset.confirmed === '1') {
        delete form.dataset.confirmed;
        return;
    }
    event.preventDefault();
    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
    void ask(form).then((confirmed) => {
        if (confirmed) {
            form.dataset.confirmed = '1';
            form.requestSubmit(submitter);
        }
    });
}, true);

document.addEventListener('click', (event) => {
    const link = (event.target as Element | null)?.closest<HTMLElement>('a[data-confirm][data-confirm-post]');
    if (link === null || link === undefined) {
        return;
    }
    event.preventDefault();
    void ask(link).then((confirmed) => {
        if (confirmed) {
            post(link);
        }
    });
});
