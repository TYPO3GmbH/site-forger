/**
 * Opens the search dialog with the search icon in the menu bar or Ctrl+K (Cmd+K on macOS).
 * Escape and a click on the backdrop close it.
 * See templates/_partials/header_search.html.twig and src/Menu/MenuBuilder.php.
 */
(function () {
    'use strict';

    const isMac = /Mac|iPhone|iPad/.test(navigator.platform);

    function isShortcut(event) {
        return event.key.toLowerCase() === 'k' && !event.altKey && !event.shiftKey
            && (isMac ? event.metaKey && !event.ctrlKey : event.ctrlKey && !event.metaKey);
    }

    function init() {
        const dialog = document.getElementById('header-search');
        if (!dialog || typeof dialog.showModal !== 'function') {
            // The search icon stays a link to the search page
            return;
        }
        const field = dialog.querySelector('input[name="query"]');
        const toggles = document.querySelectorAll('.js-header-search-toggle');
        const shortcut = isMac ? '⌘K' : 'Ctrl+K';

        function setExpanded(expanded) {
            toggles.forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            });
        }

        function open() {
            if (dialog.open) {
                field.select();
                return;
            }
            dialog.showModal();
            setExpanded(true);
            field.focus();
            field.select();
        }

        toggles.forEach(function (toggle) {
            toggle.title = 'Search (' + shortcut + ')';
            toggle.setAttribute('aria-keyshortcuts', isMac ? 'Meta+K' : 'Control+K');
            toggle.setAttribute('aria-haspopup', 'dialog');
            toggle.addEventListener('click', function (event) {
                if (event.ctrlKey || event.metaKey || event.shiftKey) {
                    return;
                }
                event.preventDefault();
                open();
            });
        });

        dialog.addEventListener('close', function () {
            setExpanded(false);
        });

        // A click on the backdrop lands on the dialog element itself
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        // Works while typing, too, like in most web apps
        document.addEventListener('keydown', function (event) {
            if (isShortcut(event)) {
                event.preventDefault();
                open();
            }
        });
    }

    window.addEventListener('DOMContentLoaded', init);
}());
