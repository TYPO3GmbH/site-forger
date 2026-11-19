/**
 * The command runs page, see templates/management/commands/index.html.twig.
 *
 * Filters and job links replace the content of the page in place instead of loading a new page,
 * so the page keeps its scroll position. While a command runs, the content is refreshed the same way,
 * paused while the tab is hidden or a message is opened, and the refresh can be switched off.
 * Without JavaScript the links and the page work as usual.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'forger.commandRuns.refresh';
    let timer = null;
    let loading = false;

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
            if (refreshEnabled() && !reading && document.visibilityState === 'visible') {
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
     * Loads the page at url and puts its content in place of the current one.
     */
    function load(url, addToHistory, reveal) {
        if (loading) {
            return Promise.resolve();
        }
        loading = true;
        const current = page();
        current.setAttribute('aria-busy', 'true');

        return window.fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
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
                current.innerHTML = next.innerHTML;
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
                loading = false;
                current.removeAttribute('aria-busy');
            });
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
            load(url, true, link.hasAttribute('data-runs-reveal')).then(schedule);
        });

        document.addEventListener('change', function (event) {
            if (event.target.matches('[data-runs-refresh-toggle]')) {
                window.localStorage.setItem(STORAGE_KEY, event.target.checked ? 'on' : 'off');
            }
        });

        window.addEventListener('popstate', function () {
            load(window.location.href, false).then(schedule);
        });
    });
})();
