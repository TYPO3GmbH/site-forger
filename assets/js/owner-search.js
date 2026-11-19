/**
 * Search in the "Owner" filter of the review lists.
 *
 * The suggestions are loaded while typing, there are too many owners to put them all into the page.
 * A picked owner is added to the filters like the checkbox of the "Owner" filter, and the form is sent.
 * Arrow keys move through the suggestions, Enter picks one, Escape closes them.
 * See templates/_partials/owner_search.html.twig and ReviewController::owners().
 */
(function () {
    'use strict';

    const MIN_LENGTH = 2;
    const DELAY = 200;

    // The search writes "filters[owner][Benni_Mack]", see ForgerTwigExtension::filterKey()
    function filterKey(name) {
        return name.replace(/ /g, '_');
    }

    // The name with the typed part marked, built from text nodes only
    function highlighted(name, term) {
        const fragment = document.createDocumentFragment();
        const start = name.toLowerCase().indexOf(term.toLowerCase());
        if (start < 0) {
            fragment.append(name);
            return fragment;
        }
        const mark = document.createElement('mark');
        mark.textContent = name.slice(start, start + term.length);
        fragment.append(name.slice(0, start), mark, name.slice(start + term.length));
        return fragment;
    }

    function initials(name) {
        return name.split(/\s+/).filter(Boolean).slice(0, 2).map(function (part) {
            return part.charAt(0).toUpperCase();
        }).join('');
    }

    function init(container) {
        const field = container.querySelector('.owner-search-input');
        const results = container.querySelector('.owner-search-results');
        const message = container.querySelector('.owner-search-message');
        const form = field.form;
        if (!form) {
            return;
        }
        let owners = [];
        let active = -1;
        let loading = null;
        let timer = null;
        let picked = false;

        function showMessage(text) {
            message.textContent = text;
            message.hidden = text === '';
        }

        function close() {
            results.hidden = true;
            results.replaceChildren();
            field.setAttribute('aria-expanded', 'false');
            field.removeAttribute('aria-activedescendant');
            active = -1;
        }

        function setActive(index) {
            const options = results.querySelectorAll('.owner-search-option');
            if (options.length === 0) {
                return;
            }
            active = (index + options.length) % options.length;
            options.forEach(function (option, position) {
                const isActive = position === active;
                option.classList.toggle('is-active', isActive);
                option.setAttribute('aria-selected', isActive ? 'true' : 'false');
                if (isActive) {
                    field.setAttribute('aria-activedescendant', option.id);
                    option.scrollIntoView({block: 'nearest'});
                }
            });
        }

        function isSelected(owner) {
            const checkbox = form.elements.namedItem('filters[' + container.dataset.filter + '][' + filterKey(owner.name) + ']');
            return checkbox instanceof HTMLInputElement && checkbox.checked;
        }

        function render(term) {
            close();
            if (owners.length === 0) {
                showMessage('No owner of a change in this list matches “' + term + '”.');
                return;
            }
            showMessage('');
            owners.forEach(function (owner, index) {
                const option = document.createElement('li');
                option.className = 'owner-search-option';
                option.id = 'owner-search-option-' + index;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');

                let avatar;
                if (owner.avatar) {
                    avatar = document.createElement('img');
                    avatar.src = owner.avatar;
                    avatar.alt = '';
                    avatar.loading = 'lazy';
                } else {
                    avatar = document.createElement('span');
                    avatar.textContent = initials(owner.name);
                }
                avatar.className = 'owner-search-avatar';

                const name = document.createElement('span');
                name.className = 'owner-search-name';
                name.append(highlighted(owner.name, term));

                const count = document.createElement('span');
                count.className = 'owner-search-count';
                count.textContent = String(owner.changes);
                count.title = owner.changes + (owner.changes === 1 ? ' change' : ' changes') + ' in this list';

                option.append(avatar, name, count);
                if (isSelected(owner)) {
                    option.classList.add('is-selected');
                    option.title = 'Selected already';
                }
                // Before the field loses the focus
                option.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    pick(owner);
                });
                option.addEventListener('mousemove', function () {
                    if (active !== index) {
                        setActive(index);
                    }
                });
                results.append(option);
            });
            results.hidden = false;
            field.setAttribute('aria-expanded', 'true');
        }

        function load(term) {
            const url = new URL(container.dataset.url, window.location.href);
            url.searchParams.set('term', term);
            // "Find by file" narrows the list with these, the filters do not change the numbers of the filter
            ['query', 'searchClosed'].forEach(function (parameter) {
                const value = new URLSearchParams(window.location.search).get(parameter);
                if (value !== null) {
                    url.searchParams.set(parameter, value);
                }
            });
            container.classList.add('is-loading');
            const request = fetch(url, {headers: {'Accept': 'application/json'}})
                .then(function (response) {
                    return response.ok ? response.json() : [];
                })
                .catch(function () {
                    return [];
                })
                .then(function (found) {
                    // An older request must not overwrite the answer to a newer one
                    if (loading !== request) {
                        return;
                    }
                    container.classList.remove('is-loading');
                    owners = found;
                    render(term);
                });
            loading = request;

            return request;
        }

        function pick(owner) {
            if (picked) {
                return;
            }
            picked = true;
            const name = 'filters[' + container.dataset.filter + '][' + filterKey(owner.name) + ']';
            const checkbox = form.elements.namedItem(name);
            if (checkbox instanceof HTMLInputElement) {
                checkbox.checked = true;
            } else {
                const filter = document.createElement('input');
                filter.type = 'hidden';
                filter.name = name;
                filter.value = 'true';
                form.appendChild(filter);
            }
            field.value = owner.name;
            // The search field itself is no filter
            field.disabled = true;
            container.classList.add('is-loading');
            form.submit();
        }

        field.addEventListener('input', function () {
            window.clearTimeout(timer);
            const term = field.value.trim();
            loading = null;
            container.classList.remove('is-loading');
            if (term.length < MIN_LENGTH) {
                owners = [];
                close();
                showMessage('');
                return;
            }
            timer = window.setTimeout(function () {
                load(term);
            }, DELAY);
        });

        field.addEventListener('keydown', function (event) {
            switch (event.key) {
                case 'ArrowDown':
                case 'ArrowUp':
                    if (!results.hidden) {
                        event.preventDefault();
                        setActive(active + (event.key === 'ArrowDown' ? 1 : -1));
                    }
                    break;
                case 'Enter': {
                    // Never sends the form with the search field as it is
                    event.preventDefault();
                    if (active >= 0 && owners[active]) {
                        pick(owners[active]);
                        break;
                    }
                    const term = field.value.trim();
                    const exact = function () {
                        return owners.find(function (owner) {
                            return owner.name.toLowerCase() === term.toLowerCase();
                        }) || (owners.length === 1 ? owners[0] : null);
                    };
                    if (exact()) {
                        pick(exact());
                    } else if (term.length >= MIN_LENGTH) {
                        window.clearTimeout(timer);
                        load(term).then(function () {
                            if (exact()) {
                                pick(exact());
                            }
                        });
                    }
                    break;
                }
                case 'Escape':
                    if (!results.hidden) {
                        event.preventDefault();
                        close();
                    }
                    break;
            }
        });

        field.addEventListener('focus', function () {
            if (owners.length > 0 && field.value.trim().length >= MIN_LENGTH) {
                render(field.value.trim());
            }
        });
        field.addEventListener('blur', function () {
            close();
        });
    }

    window.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.js-owner-search').forEach(init);
    });
}());
