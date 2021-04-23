var Encore = require('@symfony/webpack-encore');
var webpack = require('webpack');

Encore
  .setOutputPath('public/build/')
  .setPublicPath('/build')
  .addEntry('app', './assets/js/app.js')
  .splitEntryChunks()
  .enableSingleRuntimeChunk()
  .cleanupOutputBeforeBuild()
  .enableBuildNotifications()
  .enableSourceMaps(!Encore.isProduction())
  .enableVersioning()
  .addPlugin(new webpack.ProvidePlugin({
      $: 'jquery',
      jQuery: 'jquery'
  }))
  .configureBabel(() => {}, {
    useBuiltIns: 'usage',
    corejs: 3
  })
  .enableSassLoader(function(sassOptions) {}, {
    resolveUrlLoader: false
  })
  .enablePostCssLoader((options) => {
    options.postcssOptions = {
      path: 'config/postcss.config.js'
    };
  })
;

module.exports = Encore.getWebpackConfig();
