/**
 * A button with "data-copy-text" copies that text to the clipboard. Its label says "Copied" for a moment.
 */
(function () {
    'use strict';

    const RESET_DELAY = 2000;

    async function copy(button) {
        const text = button.dataset.copyText;
        const label = button.querySelector('span');
        try {
            await navigator.clipboard.writeText(text);
        } catch (error) {
            // No access to the clipboard, e.g. without HTTPS: show the text to copy it by hand
            window.prompt('Copy the text:', text);
            return;
        }
        if (label === null) {
            return;
        }
        label.dataset.label ??= label.textContent;
        label.textContent = 'Copied';
        window.clearTimeout(button.resetTimer);
        button.resetTimer = window.setTimeout(function () {
            label.textContent = label.dataset.label;
        }, RESET_DELAY);
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-copy-text]');
        if (button !== null) {
            copy(button);
        }
    });
})();
