import { fetchJson } from './request.js';

/**
 * Switches a chart card between the chart and a table of its data, in the same place.
 * The table is loaded when it is shown the first time. See templates/dashboard/index.html.twig.
 */
(function () {
    'use strict';

    const numbers = new Intl.NumberFormat();

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function colorOf(dataset) {
        const color = dataset.borderWidth === 0 || dataset.fill === undefined ? dataset.backgroundColor : dataset.borderColor;
        return Array.isArray(color) ? null : color;
    }

    function render(pane, data) {
        const datasets = data.datasets || [];
        const labels = data.tableLabels || data.labels || [];
        const table = element('table', 'overview-data-table');
        const head = table.createTHead().insertRow();
        head.appendChild(element('th', null, pane.dataset.categoryLabel || ''));
        datasets.forEach(function (dataset) {
            const th = element('th');
            const color = colorOf(dataset);
            if (color) {
                const swatch = element('span', 'overview-data-swatch');
                swatch.style.background = color;
                th.appendChild(swatch);
            }
            th.appendChild(document.createTextNode(dataset.label || 'Count'));
            head.appendChild(th);
        });
        const body = table.createTBody();
        labels.forEach(function (label, row) {
            const tr = body.insertRow();
            tr.appendChild(element('th', null, label));
            datasets.forEach(function (dataset) {
                const value = dataset.data[row];
                tr.appendChild(element('td', value === null || value === undefined ? 'is-empty' : null, value === null || value === undefined ? '–' : numbers.format(value)));
            });
        });
        // Most recent first, the rows of a time axis end with it
        if (pane.dataset.categoryLabel === 'Week' || (pane.dataset.categoryLabel === 'Month' && labels.length > 12)) {
            Array.from(body.rows).reverse().forEach(function (tr) {
                body.appendChild(tr);
            });
        }
        pane.replaceChildren(table);
    }

    function load(pane) {
        pane.replaceChildren(element('p', 'overview-data-message', 'Loading…'));
        fetchJson(pane.dataset.chartTable)
            .then(function (data) {
                render(pane, data);
            })
            .catch(function () {
                pane.replaceChildren(element('p', 'overview-data-message', 'The data could not be loaded.'));
                delete pane.dataset.loaded;
            });
    }

    function show(card, view) {
        card.querySelectorAll('[data-chart-view]').forEach(function (button) {
            const active = button.dataset.chartView === view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        card.querySelectorAll('[data-chart-pane]').forEach(function (pane) {
            pane.hidden = pane.dataset.chartPane !== view;
        });
        const table = card.querySelector('[data-chart-pane="table"]');
        if (view === 'table' && !table.dataset.loaded) {
            table.dataset.loaded = '1';
            load(table);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-chart-card]').forEach(function (card) {
            card.querySelectorAll('[data-chart-view]').forEach(function (button) {
                button.addEventListener('click', function () {
                    show(card, button.dataset.chartView);
                });
            });
        });
    });
})();
