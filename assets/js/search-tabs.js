/**
 * The tabs of the search, Reviews and Issues: each remembers its filters and its order for the browser tab, and gets
 * them back when switched to again with the same search words. The people go along from one tab to the other in the
 * link the page has, see GlobalSearch::tabFilters(): while they are the ones the tab had, it gets its own back, with
 * what the person was to it (author or assignee); else the people of the link replace its own.
 * The count of the tab is that of the link, with the people only: with filters of its own it would be another one,
 * so it is hidden then. See templates/_partials/search_tabs.html.twig.
 */
(function () {
    'use strict';

    const STORAGE_PREFIX = 'forger.searchTab.';

    // "filters[owner][benni]" -> ["owner", "benni"]
    function filter(name) {
        const match = /^filters\[([^\]]+)\]\[([^\]]*)\]$/.exec(name);
        return match ? [match[1], match[2]] : null;
    }

    // The names in the filters of people, sorted, as one string to compare
    function people(parameters, keys) {
        const names = [];
        parameters.forEach(function (value, name) {
            const parts = filter(name);
            if (parts && keys.indexOf(parts[0]) >= 0 && value === 'true' && names.indexOf(parts[1]) < 0) {
                names.push(parts[1]);
            }
        });
        return names.sort().join('\n');
    }

    function withoutPeople(parameters, keys) {
        const result = new URLSearchParams();
        parameters.forEach(function (value, name) {
            const parts = filter(name);
            if (!(parts && keys.indexOf(parts[0]) >= 0)) {
                result.append(name, value);
            }
        });
        return result;
    }

    // The filters that change what a tab finds, set: not the page, not the order
    function counted(parameters) {
        const values = [];
        parameters.forEach(function (value, name) {
            if (name !== 'page' && name !== 'sort' && value.trim() !== '') {
                values.push(name + '=' + value);
            }
        });
        return values.sort().join('&');
    }

    function read(key) {
        try {
            return window.sessionStorage.getItem(STORAGE_PREFIX + key);
        } catch (e) {
            return null;
        }
    }

    function write(key, value) {
        try {
            window.sessionStorage.setItem(STORAGE_PREFIX + key, value);
        } catch (e) {
        }
    }

    function restore(link) {
        const stored = read(link.dataset.tab);
        if (stored === null) {
            return;
        }
        const url = new URL(link.href, window.location.href);
        const own = new URLSearchParams(stored);
        // A new search starts without the filters of a tab
        if ((own.get('query') || '').trim() !== (url.searchParams.get('query') || '').trim()
            || (own.get('searchClosed') === '1') !== (url.searchParams.get('searchClosed') === '1')) {
            return;
        }
        const keys = (link.dataset.people || '').split(' ').filter(Boolean);
        let parameters = own;
        if (people(own, keys) !== people(url.searchParams, keys)) {
            // Other people: theirs from the link, and the list from the first page
            parameters = withoutPeople(own, keys);
            parameters.delete('page');
            url.searchParams.forEach(function (value, name) {
                const parts = filter(name);
                if (parts && keys.indexOf(parts[0]) >= 0) {
                    parameters.append(name, value);
                }
            });
        }
        const count = link.querySelector('.view-tab-count');
        if (count && counted(parameters) !== counted(url.searchParams)) {
            count.hidden = true;
        }
        url.search = parameters.toString();
        link.href = url.toString();
    }

    function init(nav) {
        write(nav.dataset.active, window.location.search);
        nav.querySelectorAll('a[data-tab]').forEach(function (link) {
            if (link.dataset.tab !== nav.dataset.active) {
                restore(link);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.js-search-tabs').forEach(init);
    });
})();
