// Load the CSS stuff
require('../css/app.scss');

require('./quickstats.js');
require('./rst.js');
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
