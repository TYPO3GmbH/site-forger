import Encore from '@symfony/webpack-encore';

// Configure the runtime environment if the "encore" command did not, e.g. for tools reading this file
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')
    .addEntry('app', './assets/app.js')
    .splitEntryChunks()
    .enableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()
    .enableSassLoader()
    // The elements of the activity, see assets/ts/ and tsconfig.json. "npm run typecheck" checks the types, the
    // build only strips them.
    .enableTypeScriptLoader((options) => {
        options.transpileOnly = true;
    })
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())
    .configureBabel((config) => {
        // All assets are ES modules ("type": "module"), so polyfills must be imported, not required
        config.sourceType = 'module';
        config.plugins.push(['polyfill-corejs3', { method: 'usage-global', version: '3.51' }]);
    })
    .configureCssMinimizerPlugin((options, MinimizerPlugin) => {
        options.minify = MinimizerPlugin.cssnanoMinify;
    })
    .enablePostCssLoader((options) => {
        options.postcssOptions = {
            config: 'config/postcss.config.js',
        };
    })
;

export default await Encore.getWebpackConfig();
