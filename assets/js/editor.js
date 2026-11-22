const modes = {
    rst: () => import('ace-builds/src-noconflict/mode-rst.js'),
    yaml: () => import('ace-builds/src-noconflict/mode-yaml.js'),
};

/**
 * Loads the Ace editor with the mode "ace/mode/<mode>" and the theme "ace/theme/github". The editor is large and
 * only the sprint board form and the ReST helper use it, so a page loads it when it has such a field.
 *
 * @param {'rst'|'yaml'} mode
 */
export async function loadEditor(mode) {
    const { default: ace } = await import('ace-builds/src-noconflict/ace.js');
    await Promise.all([
        modes[mode](),
        import('ace-builds/src-noconflict/theme-github.js'),
    ]);

    return ace;
}
