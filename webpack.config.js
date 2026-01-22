const Encore = require('@symfony/webpack-encore');
const webpack = require('webpack');

Encore
  .setOutputPath('public/build/')
  .setPublicPath('/build')
  .addEntry('app', './assets/app.js')
  .splitEntryChunks()
  .enableSingleRuntimeChunk()
  .cleanupOutputBeforeBuild()
  .enableBuildNotifications()
  .enableSassLoader()
  .enableSourceMaps(!Encore.isProduction())
  .enableVersioning(Encore.isProduction())
  .addPlugin(new webpack.ProvidePlugin({
      $: 'jquery',
      jQuery: 'jquery'
  }))
  .configureBabelPresetEnv((config) => {
    config.useBuiltIns = 'usage';
    config.corejs = '3.38';
  })
  .enablePostCssLoader((options) => {
    options.postcssOptions = {
      path: 'config/postcss.config.js'
    };
  })
;

module.exports = Encore.getWebpackConfig();
