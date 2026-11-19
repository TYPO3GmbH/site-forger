/**
 * Search in a filter of people: the people of the review lists, the authors and assignees of the issue search.
 *
 * The suggestions are loaded while typing, there are too many people to put them all into the page.
 * A picked person is added to the filters like their checkbox in the filter, and the form is sent.
 * Arrow keys move through the suggestions, Enter picks one, Escape closes them.
 * See templates/_partials/person_search.html.twig, ReviewController::people() and SearchController::people().
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
        const field = container.querySelector('.person-search-input');
        const idPrefix = field.id + '-option-';
        const results = container.querySelector('.person-search-results');
        const message = container.querySelector('.person-search-message');
        const form = field.form;
        if (!form) {
            return;
        }
        let people = [];
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
            const options = results.querySelectorAll('.person-search-option');
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

        function isSelected(person) {
            const checkbox = form.elements.namedItem('filters[' + container.dataset.filter + '][' + filterKey(person.name) + ']');
            return checkbox instanceof HTMLInputElement && checkbox.checked;
        }

        function render(term) {
            close();
            if (people.length === 0) {
                showMessage('Nobody in this list matches “' + term + '”.');
                return;
            }
            showMessage('');
            people.forEach(function (person, index) {
                const option = document.createElement('li');
                option.className = 'person-search-option';
                option.id = idPrefix + index;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');

                let avatar;
                if (person.avatar) {
                    avatar = document.createElement('img');
                    avatar.src = person.avatar;
                    avatar.alt = '';
                } else {
                    avatar = document.createElement('span');
                    avatar.textContent = initials(person.name);
                }
                avatar.className = 'person-search-avatar';

                const name = document.createElement('span');
                name.className = 'person-search-name';
                name.append(highlighted(person.name, term));

                const count = document.createElement('span');
                count.className = 'person-search-count';
                count.textContent = String(person.count);
                count.title = person.count + ' in this list';

                option.append(avatar, name, count);
                if (isSelected(person)) {
                    option.classList.add('is-selected');
                    option.title = 'Selected already';
                }
                // Before the field loses the focus
                option.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    pick(person);
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
            // The search words, and "Find by file", narrow the list with these. The filters do not change the numbers
            // of a filter
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
                    people = found;
                    render(term);
                });
            loading = request;

            return request;
        }

        function pick(person) {
            if (picked) {
                return;
            }
            picked = true;
            const name = 'filters[' + container.dataset.filter + '][' + filterKey(person.name) + ']';
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
            field.value = person.name;
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
                people = [];
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
                    if (active >= 0 && people[active]) {
                        pick(people[active]);
                        break;
                    }
                    const term = field.value.trim();
                    const exact = function () {
                        return people.find(function (person) {
                            return person.name.toLowerCase() === term.toLowerCase();
                        }) || (people.length === 1 ? people[0] : null);
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
            if (people.length > 0 && field.value.trim().length >= MIN_LENGTH) {
                render(field.value.trim());
            }
        });
        field.addEventListener('blur', function () {
            close();
        });
    }

    window.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.js-person-search').forEach(init);
    });
}());
