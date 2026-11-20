import { fetchHtml } from './request.js';

/*
 * Shows the sprint board wizard one step at a time and previews the issues its filters select.
 * Without JavaScript all steps are shown at once and the form still works.
 */

const Selectors = {
    wizard: 'form[data-sprint-wizard]',
    nav: '[data-wizard-nav]',
    navButton: '[data-wizard-goto]',
    step: '[data-wizard-step]',
    back: '[data-wizard-back]',
    next: '[data-wizard-next]',
    submit: '.sprint-wizard-actions [type="submit"]',
    preview: '[data-wizard-preview]',
    name: '[data-wizard-name]',
    identifier: '[data-wizard-identifier]',
    filterSelect: 'select[data-filter-select]',
    error: '.invalid-feedback, .is-invalid',
};

function slugify(value) {
    return value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

class SprintWizard {
    constructor(form) {
        this.form = form;
        this.steps = Array.from(form.querySelectorAll(Selectors.step));
        this.navButtons = Array.from(form.querySelectorAll(Selectors.navButton));
        this.back = form.querySelector(Selectors.back);
        this.next = form.querySelector(Selectors.next);
        this.submit = form.querySelector(Selectors.submit);
        this.preview = form.querySelector(Selectors.preview);
        this.previewRequest = null;
        this.current = 0;

        form.querySelector(Selectors.nav).hidden = false;
        this.back.addEventListener('click', () => this.show(this.current - 1));
        this.next.addEventListener('click', () => this.goTo(this.current + 1));
        this.navButtons.forEach((button) => {
            button.addEventListener('click', () => this.goTo(parseInt(button.dataset.wizardGoto, 10)));
        });
        // Enter moves on instead of saving a board that was not checked yet
        form.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && event.target instanceof HTMLInputElement && !this.isLastStep()) {
                event.preventDefault();
                this.goTo(this.current + 1);
            }
        });

        this.initializeIdentifier();
        form.querySelectorAll(Selectors.filterSelect).forEach((select) => this.initializeSelectSearch(select));

        // After a failed save, start at the first step that shows an error
        const stepWithError = this.steps.findIndex((step) => step.querySelector(Selectors.error) !== null);
        this.show(Math.max(stepWithError, 0));
    }

    isLastStep() {
        return this.current === this.steps.length - 1;
    }

    goTo(target) {
        // Moving forward checks every step on the way
        for (let index = this.current; index < target; index++) {
            if (!this.validate(this.steps[index])) {
                this.show(index);
                return;
            }
        }
        this.show(target);
    }

    validate(step) {
        const invalid = Array.from(step.querySelectorAll('input, select, textarea')).find((field) => !field.checkValidity());
        if (invalid === undefined) {
            return true;
        }
        this.show(this.steps.indexOf(step));
        invalid.reportValidity();

        return false;
    }

    show(index) {
        this.current = Math.min(Math.max(index, 0), this.steps.length - 1);
        this.steps.forEach((step, stepIndex) => {
            step.hidden = stepIndex !== this.current;
        });
        this.navButtons.forEach((button, buttonIndex) => {
            const item = button.closest('li');
            item.classList.toggle('active', buttonIndex === this.current);
            item.classList.toggle('done', buttonIndex < this.current);
            if (buttonIndex === this.current) {
                button.setAttribute('aria-current', 'step');
            } else {
                button.removeAttribute('aria-current');
            }
        });
        this.back.hidden = this.current === 0;
        this.next.hidden = this.isLastStep();
        this.submit.hidden = !this.isLastStep();
        if (this.isLastStep()) {
            this.loadPreview();
        }
    }

    async loadPreview() {
        this.previewRequest?.abort();
        this.previewRequest = new AbortController();
        this.preview.setAttribute('aria-busy', 'true');
        this.preview.replaceChildren(Object.assign(document.createElement('p'), {
            className: 'text-muted',
            textContent: 'Looking for matching issues …',
        }));
        try {
            const html = await fetchHtml(this.form.dataset.previewUrl, {
                method: 'POST',
                body: new FormData(this.form),
                signal: this.previewRequest.signal,
            });
            // Markup rendered by forger itself, see templates/sprint/_wizard_preview.html.twig
            this.preview.innerHTML = html;
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            this.preview.replaceChildren(Object.assign(document.createElement('div'), {
                className: 'alert alert-danger',
                textContent: error.message + ' You can still save the board.',
            }));
        } finally {
            this.preview.removeAttribute('aria-busy');
        }
    }

    /**
     * Takes the identifier from the name until it is changed by hand.
     */
    initializeIdentifier() {
        const name = this.form.querySelector(Selectors.name);
        const identifier = this.form.querySelector(Selectors.identifier);
        let linked = identifier.value === '' || identifier.value === slugify(name.value);
        identifier.addEventListener('input', () => {
            linked = identifier.value === '';
        });
        name.addEventListener('input', () => {
            if (linked) {
                identifier.value = slugify(name.value);
            }
        });
    }

    /**
     * A search field above long lists, hiding the values that do not match.
     */
    initializeSelectSearch(select) {
        if (select.options.length < 10) {
            return;
        }
        const search = Object.assign(document.createElement('input'), {
            type: 'search',
            className: 'form-control form-control-sm mb-1',
            placeholder: 'Search …',
        });
        search.setAttribute('aria-label', 'Search the values of ' + (select.labels[0]?.textContent.trim() ?? 'this filter'));
        search.addEventListener('input', () => {
            const term = search.value.trim().toLowerCase();
            Array.from(select.options).forEach((option) => {
                option.hidden = term !== '' && !option.selected && !option.text.toLowerCase().includes(term);
            });
        });
        select.before(search);
    }
}

window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(Selectors.wizard).forEach((form) => new SprintWizard(form));
});
