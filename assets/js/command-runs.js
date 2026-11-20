/**
 * The command runs page, see templates/management/commands/index.html.twig.
 *
 * Filters and job links replace the content of the page in place instead of loading a new page,
 * so the page keeps its scroll position. The content is refreshed the same way all the time, paused while
 * the tab is hidden, a message is opened or an action is being confirmed, and the refresh can be switched off.
 * A refresh changes only what changed (morph()): bars move to their new width, animations go on, the focus stays.
 *
 * The actions on a job (run now, pause, resume, cancel) ask in a dialog, post in the background and show their
 * message as a notice, see CommandRunController::back(). Without JavaScript the links, forms and the page work as usual.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'forger.commandRuns.refresh';
    const NOTICE_SECONDS = 5;
    let timer = null;
    let loading = null;
    let loadAgain = false;

    function page() {
        return document.querySelector('[data-runs-page]');
    }

    function refreshEnabled() {
        return window.localStorage.getItem(STORAGE_KEY) !== 'off';
    }

    function syncToggle() {
        const toggle = document.querySelector('[data-runs-refresh-toggle]');
        if (toggle) {
            toggle.checked = refreshEnabled();
        }
    }

    function schedule() {
        window.clearTimeout(timer);
        const current = page();
        if (!current || !current.dataset.runsRefresh) {
            return;
        }
        const seconds = parseInt(current.dataset.runsRefresh, 10) || 15;
        timer = window.setTimeout(function () {
            const reading = current.querySelector('details[open]') !== null;
            if (refreshEnabled() && !reading && !confirmOpen() && document.visibilityState === 'visible') {
                load(window.location.href, false).then(schedule);
            } else {
                schedule();
            }
        }, seconds * 1000);
    }

    let releaseOnScroll = null;

    /**
     * A shorter content would pull the page up: the content keeps the previous height as long as the
     * space it leaves is in view, and gives it back once the reader scrolled up past it.
     */
    function keepHeight(element, previousHeight) {
        const extra = previousHeight - element.offsetHeight;
        if (extra <= 0) {
            return;
        }
        element.style.minHeight = previousHeight + 'px';
        releaseOnScroll = function () {
            if (window.scrollY + window.innerHeight <= document.documentElement.scrollHeight - extra) {
                releaseHeight();
            }
        };
        window.addEventListener('scroll', releaseOnScroll, {passive: true});
    }

    function releaseHeight() {
        const current = page();
        if (current) {
            current.style.minHeight = '';
        }
        if (releaseOnScroll) {
            window.removeEventListener('scroll', releaseOnScroll);
            releaseOnScroll = null;
        }
    }

    /**
     * The key of a node among its siblings: elements with an id or data-runs-key keep their identity when the
     * list around them changes (a new run on top of the list), the others go by their tag.
     */
    function keyOf(node) {
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return '#' + node.nodeType;
        }
        return node.tagName + '|' + (node.id || node.getAttribute('data-runs-key') || '');
    }

    function syncAttributes(from, to) {
        for (const attribute of Array.from(from.attributes)) {
            if (!to.hasAttribute(attribute.name)) {
                from.removeAttribute(attribute.name);
            }
        }
        for (const attribute of Array.from(to.attributes)) {
            if (from.getAttribute(attribute.name) !== attribute.value) {
                from.setAttribute(attribute.name, attribute.value);
            }
        }
    }

    /**
     * Changes the children of from into those of to, in place: what stayed the same is not touched.
     */
    function morphChildren(from, to) {
        const wanted = Array.from(to.childNodes);
        let cursor = from.firstChild;
        for (const next of wanted) {
            const key = keyOf(next);
            let match = null;
            // The node in place, or a keyed one further down that moved up
            if (cursor && keyOf(cursor) === key) {
                match = cursor;
            } else if (next.nodeType === Node.ELEMENT_NODE && (next.id || next.hasAttribute('data-runs-key'))) {
                for (let candidate = cursor; candidate; candidate = candidate.nextSibling) {
                    if (keyOf(candidate) === key) {
                        match = candidate;
                        break;
                    }
                }
            }
            if (match) {
                if (match !== cursor) {
                    from.insertBefore(match, cursor);
                } else {
                    cursor = cursor.nextSibling;
                }
                morph(match, next);
            } else {
                from.insertBefore(document.importNode(next, true), cursor);
            }
        }
        while (cursor) {
            const rest = cursor.nextSibling;
            from.removeChild(cursor);
            cursor = rest;
        }
    }

    function morph(from, to) {
        if (from.nodeType !== Node.ELEMENT_NODE) {
            if (from.nodeValue !== to.nodeValue) {
                from.nodeValue = to.nodeValue;
            }
            return;
        }
        // A message the reader opened stays open
        const open = from.tagName === 'DETAILS' && from.open;
        syncAttributes(from, to);
        if (open) {
            from.open = true;
        }
        morphChildren(from, to);
    }

    /**
     * Loads the page at url and puts its content in place of the current one. A load asked for while one runs
     * follows it, so an action shows its effect even during a refresh.
     *
     * @param {boolean} byReader a filter or link the reader chose: the list fades while it loads
     */
    function load(url, addToHistory, reveal, byReader) {
        if (loading) {
            loadAgain = true;
            return loading;
        }
        const current = page();
        if (byReader) {
            current.setAttribute('aria-busy', 'true');
        }

        loading = window.fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (response) {
                if (!response.ok || response.redirected) {
                    throw new Error('Unexpected response ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                const next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-runs-page]');
                if (!next) {
                    throw new Error('No content');
                }
                const scrollY = window.scrollY;
                const previousHeight = current.offsetHeight;
                releaseHeight();
                morphChildren(current, next);
                if (next.dataset.runsRefresh) {
                    current.dataset.runsRefresh = next.dataset.runsRefresh;
                } else {
                    delete current.dataset.runsRefresh;
                }
                if (addToHistory) {
                    window.history.pushState({runs: true}, '', url);
                }
                keepHeight(current, previousHeight);
                // The layout scrolls smoothly, the position has to stay without an animation
                window.scrollTo({top: scrollY, behavior: 'instant'});
                syncToggle();
                if (reveal) {
                    const list = current.querySelector('[data-runs-list]');
                    if (list && list.getBoundingClientRect().top > window.innerHeight) {
                        list.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
                    }
                }
            })
            .catch(function () {
                // Whatever went wrong (e.g. the session expired), the browser loads the page itself
                window.location.href = url;
            })
            .finally(function () {
                loading = null;
                current.removeAttribute('aria-busy');
            });

        return loading.then(function () {
            if (loadAgain) {
                loadAgain = false;
                return load(window.location.href, false);
            }
        });
    }

    /**
     * A notice at the bottom of the page, for the message of an action: it covers nothing and moves nothing.
     * Success hides by itself, the others stay until closed.
     */
    function notice(type, text) {
        let container = document.querySelector('.runs-toasts');
        if (!container) {
            container = document.createElement('div');
            container.className = 'runs-toasts';
            container.setAttribute('aria-live', 'polite');
            document.body.append(container);
        }
        const item = document.createElement('div');
        item.className = 'runs-toast runs-toast-' + type;
        item.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        const message = document.createElement('span');
        message.textContent = text;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'runs-toast-close';
        close.setAttribute('aria-label', 'Close');
        close.textContent = '×';
        close.addEventListener('click', function () {
            hideNotice(item);
        });
        item.append(message, close);
        container.append(item);
        if (type === 'success') {
            window.setTimeout(function () {
                hideNotice(item);
            }, NOTICE_SECONDS * 1000);
        }
    }

    function hideNotice(item) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            item.remove();
            return;
        }
        item.classList.add('is-leaving');
        item.addEventListener('animationend', function () {
            item.remove();
        }, {once: true});
    }

    /**
     * Opens the dialog for the form of an action. The dialog posts a copy of its fields itself, so a refresh
     * that replaces the button meanwhile does not matter.
     */
    function confirmAction(dialog, form) {
        const detail = form.dataset.runsConfirmDetail || '';
        const submit = dialog.querySelector('.runs-confirm-submit');
        const target = dialog.querySelector('form');
        dialog.querySelector('.runs-confirm-title').textContent = form.dataset.runsConfirm;
        dialog.querySelector('.runs-confirm-detail').textContent = detail;
        dialog.querySelector('.runs-confirm-detail').hidden = detail === '';
        // The icon of the button on the page, and its label
        const icon = form.querySelector('button .icon');
        const label = document.createElement('span');
        label.textContent = form.dataset.runsConfirmLabel;
        submit.replaceChildren(...(icon ? [icon.cloneNode(true), label] : [label]));
        submit.className = 'runs-queue-button runs-confirm-submit runs-queue-button-' + form.dataset.runsConfirmStyle;
        target.action = form.action;
        dialog.querySelector('.runs-confirm-fields').replaceChildren(
            ...Array.from(form.querySelectorAll('input[type="hidden"]'), function (input) {
                return input.cloneNode();
            })
        );
        dialog.runsButton = form.querySelector('button');
        dialog.showModal();
        // The safe choice has the focus, Enter does not start or stop a job by accident
        dialog.querySelector('[data-runs-confirm-close]').focus();
    }

    /**
     * Posts the confirmed action in the background: the dialog closes at once, the button shows that it works,
     * the message comes as a notice and the content updates in place. If that fails, the browser posts the form.
     */
    function postAction(dialog, target) {
        const button = dialog.runsButton;
        dialog.close();
        if (button && button.isConnected) {
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;
        }
        window.fetch(target.action, {
            method: 'POST',
            body: new FormData(target),
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
        })
            .then(function (response) {
                if (!response.ok || response.redirected || !(response.headers.get('Content-Type') || '').includes('json')) {
                    throw new Error('Unexpected response ' + response.status);
                }
                return response.json();
            })
            .then(function (result) {
                (result.messages || []).forEach(function (message) {
                    notice(message.type, message.text);
                });
                return load(window.location.href, false).then(schedule);
            })
            .catch(function () {
                target.submit();
            });
    }

    function confirmOpen() {
        const dialog = document.getElementById('runs-confirm');
        return dialog !== null && dialog.open;
    }

    window.addEventListener('DOMContentLoaded', function () {
        if (!page()) {
            return;
        }
        syncToggle();
        schedule();

        document.addEventListener('click', function (event) {
            const link = event.target.closest('[data-runs-link]');
            if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            // Without the fragment: the page keeps its position instead of jumping to the list
            const url = link.href.split('#')[0];
            load(url, true, link.hasAttribute('data-runs-reveal'), true).then(schedule);
        });

        // Every action on a job asks first in the dialog: they start, stop or drop runs
        const dialog = document.getElementById('runs-confirm');
        if (dialog && typeof dialog.showModal === 'function') {
            document.addEventListener('submit', function (event) {
                const form = event.target.closest('[data-runs-confirm]');
                if (form) {
                    event.preventDefault();
                    confirmAction(dialog, form);
                } else if (dialog.contains(event.target)) {
                    event.preventDefault();
                    postAction(dialog, event.target);
                }
            });
            dialog.querySelector('[data-runs-confirm-close]').addEventListener('click', function () {
                dialog.close();
            });
            // A click on the backdrop lands on the dialog element itself
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        }

        document.addEventListener('change', function (event) {
            if (event.target.matches('[data-runs-refresh-toggle]')) {
                window.localStorage.setItem(STORAGE_KEY, event.target.checked ? 'on' : 'off');
            }
        });

        window.addEventListener('popstate', function () {
            load(window.location.href, false, false, true).then(schedule);
        });
    });
})();
