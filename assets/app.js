/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './css/app.scss';

import './js/rst.js';
import './js/sprint-wizard.js';
import './js/header-search.js';
import './js/command-runs.js';
import './js/person-search.js';
import './js/search-tabs.js';
import './js/flash-messages.js';
import './js/copy.js';
import './ts/activity/index.ts';
import './ts/filter/path-filter.ts';
import './ts/filter/days-filter.ts';
import './ts/fullscreen/fullscreen.ts';
import './ts/sprint/stuck-heads.ts';
import { loadEditor } from './js/editor.js';

function initializeEditor(ace, textarea) {
    const style = window.getComputedStyle(textarea);
    const editDiv = document.createElement('div');
    // Same size as the content box of the textarea it replaces
    const paddingX = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
    const paddingY = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
    editDiv.style.width = (textarea.clientWidth - paddingX) + 'px';
    editDiv.style.height = (textarea.clientHeight - paddingY) + 'px';
    textarea.before(editDiv);
    textarea.style.display = 'none';

    const editor = ace.edit(editDiv);
    editor.renderer.setShowGutter(true);
    editor.setShowPrintMargin(false);
    // The syntax check workers are not part of the build
    editor.setOption('useWorker', false);
    editor.getSession().setValue(textarea.value);
    editor.getSession().setMode('ace/mode/' + textarea.dataset.editor);
    editor.setTheme('ace/theme/github');
    editor.setFontSize('18px');

    editor.getSession().on('change', function () {
        textarea.value = editor.getSession().getValue();
    });
}

window.addEventListener('DOMContentLoaded', async function () {
    const textareas = document.querySelectorAll('textarea[data-editor]');
    if (textareas.length === 0) {
        return;
    }
    for (const textarea of textareas) {
        initializeEditor(await loadEditor(textarea.dataset.editor), textarea);
    }
});
