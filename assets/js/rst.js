import { sprintf } from 'sprintf-js';

(function() {
    'use strict';

    const Selectors = {
        form: '.form-rst-helper',
        classificationToggle: 'input[name="classification"]',
        ticketIdField: '#ticketid',
        ticketTitleField: '#title',
        fetchTicket: '[data-action="fetch-ticket"]',
        modal: '#changelogModal',
        flashMessageContainer: '.flashmessage-container'
    };

    const Rst = {
        initialize: function () {
            if (document.querySelector(Selectors.form) !== null) {
                Rst.initializeEvents();
            }
        },
        initializeEvents: function () {
            Rst.togglePalettes('breaking');
            document.addEventListener('change', function (e) {
                if (e.target.matches(Selectors.classificationToggle)) {
                    Rst.togglePalettes(e.target.value);
                }
            });
            document.addEventListener('click', function (e) {
                const trigger = e.target.closest(Selectors.fetchTicket);
                if (trigger !== null) {
                    Rst.fetchTicket(trigger);
                }
            });

            document.querySelector(Selectors.form).addEventListener('submit', Rst.generateDocument);
        },
        fetchTicket: async function (trigger) {
            const ticketIdField = document.querySelector(Selectors.ticketIdField);
            const titleField = document.querySelector(Selectors.ticketTitleField);
            const ticketId = parseInt(ticketIdField.value.replace(/\D/g, ''));

            if (isNaN(ticketId)) {
                return;
            }

            ticketIdField.disabled = true;
            trigger.disabled = true;
            document.querySelector(Selectors.flashMessageContainer).replaceChildren();
            try {
                const response = await fetch('/api/issue/' + ticketId, {headers: {Accept: 'application/json'}});
                const data = await response.json().catch(() => null);
                if (!response.ok || data === null) {
                    throw new Error(typeof data === 'string' ? data : 'Fetching the ticket failed with status ' + response.status);
                }
                titleField.value = data.subject;
            } catch (error) {
                Rst.showError(error.message);
            } finally {
                ticketIdField.disabled = false;
                trigger.disabled = false;
            }
        },
        showError: function (message) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger alert-dismissible fade show';
            alert.setAttribute('role', 'alert');
            const text = document.createElement('strong');
            text.textContent = message;
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'btn-close';
            close.dataset.bsDismiss = 'alert';
            close.setAttribute('aria-label', 'Close');
            alert.append(text, close);
            document.querySelector(Selectors.flashMessageContainer).append(alert);
        },
        togglePalettes: function (classification) {
            document.querySelectorAll('[data-classified-as]').forEach(function (palette) {
                const show = palette.dataset.classifiedAs.split(' ').includes(classification);
                palette.hidden = !show;
                palette.querySelectorAll('input, select, textarea, button').forEach(function (field) {
                    field.disabled = !show;
                });
            });
        },
        generateDocument: function (e) {
            e.preventDefault();

            const ticketId = document.querySelector(Selectors.ticketIdField).value.replace(/\D/g, '');
            const timestamp = Math.floor(Date.now() / 1000);
            const title = document.querySelector(Selectors.ticketTitleField).value.trim();
            const classificationType = document.querySelector(Selectors.classificationToggle + ':checked').value;
            const classificationTitle = document.querySelector(Selectors.classificationToggle + ':checked').parentElement.textContent.trim();
            const headline = sprintf('%s: #%d - %s', classificationTitle, ticketId, title);
            const filename = sprintf('%s-%d-%s', classificationTitle, ticketId, title.toUpperCamelCase().replace(/[^0-9a-z-_]/gi, '')) + '.rst';

            let restBody = '';
            const forgeRef = 'See :issue:`' + ticketId + '`';
            let restContent = '..  include:: /Includes.rst.txt\n\n';
            // Add label for the document type being the documentation type (e.g. breaking, important), the ticket ID, and the title
            restContent += sprintf('..  _%s-%d-%d:\n\n', classificationType, ticketId, timestamp);
            restContent += sprintf('%s\n%s\n%s\n\n%s', '='.repeat(headline.length), headline, '='.repeat(headline.length), forgeRef);

            // Only the sections of the current classification are visible
            e.currentTarget.querySelectorAll('textarea').forEach(function (textarea) {
                const label = textarea.title;
                const value = textarea.value.trim();

                if (textarea.getClientRects().length > 0 && value.length > 0) {
                    restBody += sprintf('\n\n\n%s\n%s\n\n%s', label, '='.repeat(label.length), value);
                }
            });

            const checkedTags = Array.from(document.querySelectorAll('input[name="tag[]"]:checked'));
            if (checkedTags.length > 0) {
                const tags = checkedTags.map(function (tagField) {
                    if (['Feature', 'Important'].indexOf(classificationTitle) > -1
                        && ['NotScanned', 'PartiallyScanned', 'FullyScanned'].indexOf(tagField.value) > -1
                    ) {
                        // Filter extension scanner tags in case of being a "Feature" or "Important"
                        return null;
                    }
                    return tagField.value;
                }).filter(tag => tag !== null);

                const extensionTag = document.querySelector('select[name="tag[]"]').value;
                if (extensionTag.length > 0) {
                    tags.push(extensionTag);
                }
                restBody += sprintf('\n\n..  index:: %s', tags.join(', '));
            }
            restContent += restBody.replace('\n', '');

            const icon = document.querySelector(`${Selectors.modal} template[data-template="download-icon"]`).content.firstElementChild.cloneNode(true);
            icon.classList.add('me-1');
            const link = document.createElement('a');
            link.setAttribute('href', window.URL.createObjectURL(new Blob([restContent], {type: 'text/plain;charset=utf-8'})));
            link.setAttribute('download', filename);
            link.setAttribute('title', `Download "${filename}"`);
            link.textContent = filename;
            link.prepend(icon);

            document.querySelector(`${Selectors.modal} .modal-title`).replaceChildren(link);
            document.querySelector(`${Selectors.modal} .modal-body textarea`).value = restContent;
        }
    };

    window.addEventListener('DOMContentLoaded', function() {
        Rst.initialize();
    });
})();

String.prototype.toUpperCamelCase = function () {
    return this.replace(/\w\S*/g, function (txt) {
        return txt.charAt(0).toUpperCase() + txt.substr(1);
    }).replace(/\s/g, '');
};
