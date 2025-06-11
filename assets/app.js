/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './css/app.scss';

require('./js/quickstats.js');
require('./js/rst.js');

let ace = require('ace-builds/src-noconflict/ace');
require('ace-builds/src-noconflict/mode-yaml');
require('ace-builds/src-noconflict/theme-github');

$(function () {
    $('textarea[data-editor]').each(function () {
        var textarea = $(this);

        var mode = textarea.data('editor');

        var editDiv = $('<div>', {
            position: 'absolute',
            width: textarea.width(),
            height: textarea.height(),
        }).insertBefore(textarea);

        textarea.css('display', 'none');

        var editor = ace.edit(editDiv[0]);
        editor.renderer.setShowGutter(true);
        editor.setShowPrintMargin(false);
        editor.getSession().setValue(textarea.val());
        editor.getSession().setMode("ace/mode/" + mode);
        editor.setTheme("ace/theme/github");
        editor.setFontSize("18px");

        editor.getSession().on('change', function(){
            textarea.val(editor.getSession().getValue());
        });

    });
});
