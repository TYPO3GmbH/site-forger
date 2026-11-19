import { sprintf } from 'sprintf-js';
import ace from 'ace-builds/src-noconflict/ace.js';
import 'ace-builds/src-noconflict/mode-rst.js';
import 'ace-builds/src-noconflict/theme-github.js';

(function() {
    'use strict';

    const Selectors = {
        form: '.form-rst-helper',
        classification: 'input[name="classification"]',
        target: '#target',
        ticketIdField: '#ticketid',
        titleField: '#title',
        topicTags: 'input[data-tag="topic"]',
        scannerTag: '#scannerTag',
        extensionTag: '#extensionTag',
        section: '.rst-section',
        sectionHead: '.rst-section-head',
        editorContainer: '[data-editor-container]',
        previewContainer: '[data-preview-container]',
        codeLanguage: '[data-code-language]',
        role: '[data-role]',
        fetchTicket: '[data-action="fetch-ticket"]',
        copy: '[data-action="copy"]',
        download: '[data-action="download"]',
        filename: '[data-output="filename"]',
        paths: '[data-container="changelog-paths"]',
        pathTemplate: 'template[data-template="changelog-path"]',
        result: '.rst-result',
        viewSwitch: '[data-view]',
        viewOnly: '[data-view-only]',
        statusSummary: '[data-output="status-summary"]',
        statusItems: '[data-container="status-items"]',
        reset: '[data-action="reset"]',
        draftState: '[data-output="draft-state"]',
        flashMessageContainer: '.flashmessage-container'
    };

    // The draft survives a reload of the page, until "Start over"
    const DraftStorageKey = 'site-forger.rst-helper.draft';
    const DraftVersion = 1;

    const ChangelogDirectory = 'typo3/sysext/core/Documentation/Changelog/';
    const MaximumTags = 5;
    const Indentation = '    ';

    const toUpperCamelCase = function (text) {
        return text.replace(/\w\S*/g, function (word) {
            return word.charAt(0).toUpperCase() + word.substring(1);
        }).replace(/\s/g, '');
    };

    const underline = function (text) {
        return '='.repeat(text.length);
    };

    const createEditor = function (container, options) {
        const editor = ace.edit(container);
        editor.setTheme('ace/theme/github');
        editor.session.setMode('ace/mode/rst');
        editor.session.setUseWrapMode(true);
        editor.session.setTabSize(4);
        editor.session.setUseSoftTabs(true);
        editor.setOptions(Object.assign({
            fontSize: '13px',
            // The core files wrap their text at 80 characters
            printMarginColumn: 80,
            showPrintMargin: true,
            useWorker: false
        }, options));
        return editor;
    };

    const Rst = {
        // The label of the document stays the same while the draft lives
        timestamp: Math.floor(Date.now() / 1000),
        draftTimer: null,
        downloadUrl: null,
        editors: {},
        activeEditor: null,
        preview: null,

        initialize: function () {
            const form = document.querySelector(Selectors.form);
            if (form === null) {
                return;
            }

            Rst.initializeEditors(form);

            form.addEventListener('input', Rst.render);
            form.addEventListener('change', function (e) {
                if (e.target.matches(Selectors.classification)) {
                    Rst.togglePalettes(e.target.value);
                }
                Rst.render();
            });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
            });
            document.querySelector(Selectors.ticketIdField).addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    Rst.fetchTicket(document.querySelector(Selectors.fetchTicket));
                }
            });
            form.addEventListener('click', function (e) {
                const viewSwitch = e.target.closest(Selectors.viewSwitch);
                if (viewSwitch !== null) {
                    Rst.showView(viewSwitch.dataset.view);
                }
                const insert = e.target.closest('[data-insert]');
                if (insert !== null) {
                    Rst.insert(insert);
                }
                const sectionHead = e.target.closest(Selectors.sectionHead);
                if (sectionHead !== null) {
                    Rst.editors[sectionHead.closest(Selectors.section).dataset.section].focus();
                }
                const fetchTrigger = e.target.closest(Selectors.fetchTicket);
                if (fetchTrigger !== null) {
                    Rst.fetchTicket(fetchTrigger);
                }
                if (e.target.closest(Selectors.reset) !== null) {
                    Rst.startOver(form);
                }
                const copyTrigger = e.target.closest(Selectors.copy);
                if (copyTrigger !== null) {
                    Rst.copy(copyTrigger);
                }
            });

            Rst.restoreDraft();
            Rst.togglePalettes(document.querySelector(Selectors.classification + ':checked').value);
            Rst.render();
            // Nothing changed yet, keep "Draft restored"
            window.clearTimeout(Rst.draftTimer);
            Rst.fitIntoWindow(form);
        },
        initializeEditors: function (form) {
            form.querySelectorAll(Selectors.section).forEach(function (section) {
                const container = section.querySelector(Selectors.editorContainer);
                // The editor grows with its text, the column scrolls
                const editor = createEditor(container, {minLines: 6, maxLines: Infinity});
                editor.session.on('change', Rst.render);
                editor.on('focus', function () {
                    Rst.activeEditor = editor;
                    container.classList.add('is-focused');
                });
                editor.on('blur', () => container.classList.remove('is-focused'));
                editor.commands.addCommand({
                    name: 'bold',
                    bindKey: {win: 'Ctrl-B', mac: 'Command-B'},
                    exec: () => Rst.wrapSelection('**', '**')
                });
                editor.commands.addCommand({
                    name: 'italic',
                    bindKey: {win: 'Ctrl-I', mac: 'Command-I'},
                    exec: () => Rst.wrapSelection('*', '*')
                });
                Rst.editors[section.dataset.section] = editor;
            });
            Rst.activeEditor = Rst.editors.description;

            Rst.preview = createEditor(form.querySelector(Selectors.previewContainer), {
                readOnly: true,
                highlightActiveLine: false,
                highlightGutterLine: false
            });
            // Lines as in the file, wrapped underlines would look broken
            Rst.preview.session.setUseWrapMode(false);
            Rst.preview.setFontSize('12px');
        },
        /**
         * From lg on the editor ends at the bottom of the window, the CSS needs to know where it starts
         */
        fitIntoWindow: function (form) {
            const update = function () {
                form.style.setProperty('--rst-editor-top', (form.getBoundingClientRect().top + window.scrollY) + 'px');
            };
            update();
            window.addEventListener('resize', update);
            const observer = new ResizeObserver(function (entries) {
                entries.forEach(entry => entry.target.env?.editor?.resize());
            });
            form.querySelectorAll(Selectors.editorContainer + ', ' + Selectors.previewContainer).forEach(container => observer.observe(container));
        },
        fetchTicket: async function (trigger) {
            const ticketIdField = document.querySelector(Selectors.ticketIdField);
            const titleField = document.querySelector(Selectors.titleField);
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
                Rst.render();
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
            Object.values(Rst.editors).forEach(editor => editor.resize());
            if (Rst.activeEditor.container.closest(Selectors.section).hidden) {
                Rst.activeEditor = Rst.editors.description;
            }
        },
        /**
         * The sections of the current type
         */
        visibleSections: function () {
            return Array.from(document.querySelectorAll(Selectors.section)).filter(section => !section.hidden);
        },
        /**
         * The tags in the order of the core files: topics, ExtensionScanner, extension
         */
        collectTags: function () {
            const tags = Array.from(document.querySelectorAll(Selectors.topicTags + ':checked')).map(field => field.value);
            const scannerTag = document.querySelector(Selectors.scannerTag);
            if (!scannerTag.disabled) {
                tags.push(scannerTag.value);
            }
            const extensionTag = document.querySelector(Selectors.extensionTag).value;
            if (extensionTag.length > 0) {
                tags.push(extensionTag);
            }
            return tags;
        },
        render: function () {
            const ticketIdField = document.querySelector(Selectors.ticketIdField);
            const titleField = document.querySelector(Selectors.titleField);
            const ticketId = ticketIdField.value.replace(/\D/g, '');
            const title = titleField.value.replace(/\s+/g, ' ').trim();
            const classification = document.querySelector(Selectors.classification + ':checked');
            const classificationTitle = classification.dataset.title;
            const tags = Rst.collectTags();
            // What is still missing, each with the field to fix it in
            const missing = [];

            if (ticketId.length === 0) {
                missing.push({label: 'Ticket ID', field: ticketIdField});
            }
            if (title.length === 0) {
                missing.push({label: 'Title', field: titleField});
            }

            const headline = sprintf('%s: #%s - %s', classificationTitle, ticketId, title);
            const filename = sprintf('%s-%s-%s.rst', classificationTitle, ticketId, toUpperCamelCase(title).replace(/[^0-9a-z-_]/gi, ''));
            const blocks = [
                '..  include:: /Includes.rst.txt',
                // Label of the document type (e.g. breaking, important), the ticket ID and a unique timestamp
                sprintf('..  _%s-%s-%d:', classification.value, ticketId, Rst.timestamp),
                [underline(headline), headline, underline(headline)].join('\n'),
                'See :issue:`' + ticketId + '`'
            ];

            // All sections of the current type are required
            Rst.visibleSections().forEach(function (section) {
                const label = section.dataset.label;
                const editor = Rst.editors[section.dataset.section];
                const value = editor.getValue().replace(/\s+$/, '').replace(/^\n+/, '');
                section.classList.toggle('is-empty', value.length === 0);
                if (value.length === 0) {
                    missing.push({label: label, editor: editor});
                    return;
                }
                blocks.push(label + '\n' + underline(label) + '\n\n' + value);
            });

            // Changelog/Howto.rst asks for at least two tags and, as a rule of thumb, no more than five
            const firstTopicTag = document.querySelector(Selectors.topicTags);
            if (document.querySelector(Selectors.topicTags + ':checked') === null) {
                missing.push({label: 'A tag', field: firstTopicTag, highlight: firstTopicTag.parentElement});
            }
            if (document.querySelector(Selectors.extensionTag).value.length === 0) {
                missing.push({label: 'Core extension', field: document.querySelector(Selectors.extensionTag)});
            }
            if (tags.length > MaximumTags) {
                missing.push({label: `At most ${MaximumTags} tags (${tags.length})`, field: firstTopicTag, highlight: firstTopicTag.parentElement});
            }
            if (tags.length > 0) {
                blocks.push('..  index:: ' + tags.join(', '));
            }

            const content = blocks.join('\n\n') + '\n';
            const scrollTop = Rst.preview.session.getScrollTop();
            Rst.preview.setValue(content, -1);
            Rst.preview.session.setScrollTop(scrollTop);
            document.querySelector(Selectors.filename).textContent = filename;
            document.querySelector(Selectors.filename).title = filename;
            Rst.renderPaths(filename);
            Rst.renderStatus(missing);
            Rst.updateDownload(content, filename);
            Rst.scheduleDraft();
        },
        /**
         * Everything the user entered
         */
        collectDraft: function () {
            const sections = {};
            Object.entries(Rst.editors).forEach(([section, editor]) => sections[section] = editor.getValue());
            return {
                version: DraftVersion,
                timestamp: Rst.timestamp,
                ticketId: document.querySelector(Selectors.ticketIdField).value,
                title: document.querySelector(Selectors.titleField).value,
                classification: document.querySelector(Selectors.classification + ':checked').value,
                target: document.querySelector(Selectors.target).value,
                topicTags: Array.from(document.querySelectorAll(Selectors.topicTags + ':checked')).map(field => field.value),
                extensionTag: document.querySelector(Selectors.extensionTag).value,
                scannerTag: document.querySelector(Selectors.scannerTag).value,
                sections: sections
            };
        },
        isEmptyDraft: function (draft) {
            return draft.ticketId.trim() === '' && draft.title.trim() === '' && draft.topicTags.length === 0
                && draft.extensionTag === '' && Object.values(draft.sections).every(value => value.trim() === '');
        },
        scheduleDraft: function () {
            window.clearTimeout(Rst.draftTimer);
            Rst.draftTimer = window.setTimeout(Rst.saveDraft, 400);
        },
        saveDraft: function () {
            const draft = Rst.collectDraft();
            try {
                if (Rst.isEmptyDraft(draft)) {
                    window.localStorage.removeItem(DraftStorageKey);
                    Rst.showDraftState('');
                    return;
                }
                window.localStorage.setItem(DraftStorageKey, JSON.stringify(draft));
                Rst.showDraftState('Draft saved');
            } catch (error) {
                // No storage (e.g. private mode or full): the helper works without it
                Rst.showDraftState('Draft not saved');
            }
        },
        restoreDraft: function () {
            let draft = null;
            try {
                draft = JSON.parse(window.localStorage.getItem(DraftStorageKey));
            } catch (error) {
                return;
            }
            if (draft === null || draft.version !== DraftVersion) {
                return;
            }
            const setValue = function (selector, value) {
                const field = document.querySelector(selector);
                // Only values the form still offers, e.g. an extension can be gone
                if (typeof value === 'string' && (field.tagName !== 'SELECT' || Array.from(field.options).some(option => option.value === value))) {
                    field.value = value;
                }
            };
            Rst.timestamp = draft.timestamp ?? Rst.timestamp;
            setValue(Selectors.ticketIdField, draft.ticketId);
            setValue(Selectors.titleField, draft.title);
            setValue(Selectors.target, draft.target);
            setValue(Selectors.extensionTag, draft.extensionTag);
            setValue(Selectors.scannerTag, draft.scannerTag);
            const classification = document.querySelector(`${Selectors.classification}[value="${CSS.escape(draft.classification ?? '')}"]`);
            if (classification !== null) {
                classification.checked = true;
            }
            document.querySelectorAll(Selectors.topicTags).forEach(function (field) {
                field.checked = (draft.topicTags ?? []).includes(field.value);
            });
            Object.entries(draft.sections ?? {}).forEach(function ([section, value]) {
                if (Rst.editors[section] && typeof value === 'string') {
                    Rst.editors[section].setValue(value, 1);
                }
            });
            Rst.showDraftState('Draft restored');
        },
        startOver: function (form) {
            if (!Rst.isEmptyDraft(Rst.collectDraft()) && !window.confirm('Discard this draft and start a new file?')) {
                return;
            }
            window.clearTimeout(Rst.draftTimer);
            try {
                window.localStorage.removeItem(DraftStorageKey);
            } catch (error) {
                // Nothing stored
            }
            form.reset();
            Object.values(Rst.editors).forEach(editor => editor.setValue('', -1));
            Rst.timestamp = Math.floor(Date.now() / 1000);
            Rst.showView('write');
            Rst.togglePalettes(document.querySelector(Selectors.classification + ':checked').value);
            Rst.render();
            document.querySelector(Selectors.ticketIdField).focus();
        },
        showDraftState: function (text) {
            document.querySelector(Selectors.draftState).textContent = text;
        },
        renderPaths: function (filename) {
            const template = document.querySelector(Selectors.pathTemplate);
            const directories = document.querySelector(Selectors.target).value.split(' ');
            const container = document.querySelector(Selectors.paths);
            const paths = directories.map(function (directory, index) {
                // Keep the existing rows, so the feedback of a copy button is not lost while typing
                const path = container.children[index] ?? template.content.firstElementChild.cloneNode(true);
                const fullPath = ChangelogDirectory + directory + '/' + filename;
                const shownPath = path.querySelector('[data-output="directory"]');
                shownPath.textContent = '…/Changelog/' + directory + '/' + filename;
                shownPath.title = fullPath;
                path.querySelector(Selectors.copy).dataset.copy = fullPath;
                path.querySelector(Selectors.copy).title = 'Copy the path in the core repository';
                return path;
            });
            container.replaceChildren(...paths);
        },
        renderStatus: function (missing) {
            const status = document.querySelector(Selectors.result);
            status.classList.toggle('is-complete', missing.length === 0);
            status.classList.toggle('is-open', missing.length > 0);
            document.querySelector(Selectors.statusSummary).textContent = missing.length === 0
                ? '✓ Complete, ready for the patch'
                : 'Still missing:';
            document.querySelector(Selectors.statusItems).replaceChildren(...missing.map(function (item) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = item.label;
                button.title = 'Go to ' + item.label;
                button.addEventListener('click', () => Rst.goTo(item));
                const listItem = document.createElement('li');
                listItem.append(button);
                return listItem;
            }));
        },
        /**
         * Writing the sections or looking at the whole file
         */
        showView: function (view) {
            document.querySelectorAll(Selectors.viewSwitch).forEach(function (button) {
                const active = button.dataset.view === view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            document.querySelectorAll(Selectors.viewOnly).forEach(function (element) {
                element.hidden = element.dataset.viewOnly !== view;
            });
            if (view === 'write') {
                Object.values(Rst.editors).forEach(editor => editor.resize());
                Rst.activeEditor.focus();
            } else {
                Rst.preview.resize();
            }
        },
        /**
         * Brings the field of a missing item into view and focuses it
         */
        goTo: function (item) {
            if (item.editor) {
                Rst.showView('write');
            }
            const element = item.editor ? item.editor.container : (item.highlight ?? item.field);
            Rst.scrollIntoPanel(element);
            element.classList.remove('rst-attention');
            // Restart the animation
            void element.offsetWidth;
            element.classList.add('rst-attention');
            element.addEventListener('animationend', () => element.classList.remove('rst-attention'), {once: true});
            if (item.editor) {
                item.editor.focus();
            } else {
                item.field.focus({preventScroll: true});
            }
        },
        /**
         * Scrolls the column of the element, the page itself stays where it is
         */
        scrollIntoPanel: function (element) {
            const scroller = element.closest('.rst-panel-scroll');
            if (scroller === null) {
                return;
            }
            const offset = element.getBoundingClientRect().top - scroller.getBoundingClientRect().top;
            scroller.scrollTo({top: scroller.scrollTop + offset - scroller.clientHeight / 3, behavior: 'smooth'});
        },
        updateDownload: function (content, filename) {
            if (Rst.downloadUrl !== null) {
                window.URL.revokeObjectURL(Rst.downloadUrl);
            }
            Rst.downloadUrl = window.URL.createObjectURL(new Blob([content], {type: 'text/plain;charset=utf-8'}));
            const link = document.querySelector(Selectors.download);
            link.href = Rst.downloadUrl;
            link.download = filename;
            link.title = `Download "${filename}"`;
        },
        insert: function (trigger) {
            switch (trigger.dataset.insert) {
                case 'wrap':
                    Rst.wrapSelection(trigger.dataset.before, trigger.dataset.after);
                    break;
                case 'role':
                    Rst.wrapSelection(':' + document.querySelector(Selectors.role).value + ':`', '`');
                    break;
                case 'link':
                    Rst.insertLink();
                    break;
                case 'list':
                    Rst.insertList();
                    break;
                case 'code-block':
                    Rst.insertCodeBlock(document.querySelector(Selectors.codeLanguage).value);
                    break;
            }
            Rst.activeEditor.focus();
        },
        wrapSelection: function (before, after) {
            const editor = Rst.activeEditor;
            const selected = editor.getSelectedText();
            editor.insert(before + selected + after);
            if (selected.length === 0) {
                editor.navigateLeft(after.length);
            }
        },
        insertLink: function () {
            const editor = Rst.activeEditor;
            const text = editor.getSelectedText() || 'Link text';
            editor.insert('`' + text + ' <https://>`_');
            // The cursor ends up behind "https://"
            editor.navigateLeft(3);
        },
        /**
         * The selected lines, or the line of the cursor, become the items of a bullet list
         */
        insertList: function () {
            const editor = Rst.activeEditor;
            const session = editor.getSession();
            const range = editor.getSelectionRange();
            for (let row = range.start.row; row <= range.end.row; row++) {
                const line = session.getLine(row);
                if (!/^\*\s{3}/.test(line)) {
                    session.replace({start: {row: row, column: 0}, end: {row: row, column: line.length}}, '*   ' + line.trimStart());
                }
            }
            editor.clearSelection();
            editor.navigateTo(range.end.row, session.getLine(range.end.row).length);
        },
        /**
         * The selection becomes the indented content of the block, which needs blank lines around it
         */
        insertCodeBlock: function (language) {
            const editor = Rst.activeEditor;
            const session = editor.getSession();
            const selected = editor.getSelectedText();
            const cursor = editor.getSelectionRange().start;
            const lineBefore = session.getLine(cursor.row).substring(0, cursor.column);
            const previousLine = cursor.row > 0 ? session.getLine(cursor.row - 1) : '';

            let prefix = '';
            if (lineBefore.trim().length > 0) {
                prefix = '\n\n';
            } else if (previousLine.trim().length > 0) {
                prefix = '\n';
            }
            const code = selected.length > 0
                ? selected.replace(/\s+$/, '').split('\n').map(line => line.length > 0 ? Indentation + line : line).join('\n') + '\n\n'
                : Indentation;

            editor.insert(prefix + '..  code-block:: ' + language + '\n\n' + code);
        },
        copy: async function (trigger) {
            const text = trigger.dataset.copy === 'content' ? Rst.preview.getValue() : trigger.dataset.copy;
            // The label is the last element, the icon comes before it
            const label = trigger.lastElementChild;
            label.dataset.label ??= label.textContent;
            window.clearTimeout(trigger.resetTimer);
            try {
                await navigator.clipboard.writeText(text);
                label.textContent = 'Copied';
            } catch (error) {
                // No clipboard access (e.g. without HTTPS): show the text to copy it by hand
                window.prompt('Copy the text:', text);
            }
            trigger.resetTimer = window.setTimeout(function () {
                label.textContent = label.dataset.label;
            }, 2000);
        }
    };

    window.addEventListener('DOMContentLoaded', function() {
        Rst.initialize();
    });
})();
