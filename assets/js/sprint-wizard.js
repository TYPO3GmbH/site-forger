import { fetchHtml } from './request.js';

/*
 * The form of a sprint board in one view, see templates/sprint/wizard.html.twig: it previews the issues or the
 * changes its filters select beside them, and loads them again a moment after a filter changes. The
 * lists of values are picked as chips, see chip-select.ts. Without JavaScript the form works and checks the filters
 * when it is saved.
 */

const Selectors = {
    wizard: 'form[data-sprint-wizard]',
    preview: '[data-wizard-preview]',
};

// The fields the preview follows: the filters and the order of the cards, not the name or the people
const FILTER_FIELD = /\[(query|reviewFilter|reviewSort)\]/;
const PREVIEW_DELAY = 150;

class SprintWizard {
    constructor(form) {
        this.form = form;
        this.preview = form.querySelector(Selectors.preview);
        this.previewRequest = null;
        this.previewTimer = 0;

        form.addEventListener('change', (event) => {
            if (FILTER_FIELD.test(event.target.name ?? '')) {
                window.clearTimeout(this.previewTimer);
                this.previewTimer = window.setTimeout(() => this.loadPreview(), PREVIEW_DELAY);
            }
        });
        this.loadPreview();
    }

    async loadPreview() {
        this.previewRequest?.abort();
        this.previewRequest = new AbortController();
        // What it showed stays while it loads, see [aria-busy] in _sprint-board.scss: no jump of the column
        this.preview.setAttribute('aria-busy', 'true');
        try {
            const html = await fetchHtml(this.form.dataset.previewUrl, {
                method: 'POST',
                body: new FormData(this.form),
                signal: this.previewRequest.signal,
            });
            // Markup rendered by forger itself, see templates/sprint/_wizard_preview.html.twig
            this.preview.innerHTML = html;
        } catch (error) {
            // A newer request took over and ends the loading itself
            if (error.name === 'AbortError') {
                return;
            }
            this.preview.replaceChildren(Object.assign(document.createElement('div'), {
                className: 'alert alert-danger',
                textContent: error.message + ' You can still save the board.',
            }));
        }
        this.preview.removeAttribute('aria-busy');
    }
}

window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(Selectors.wizard).forEach((form) => new SprintWizard(form));
});
