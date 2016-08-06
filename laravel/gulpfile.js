var elixir = require('laravel-elixir');
var gulp = require('gulp');
var uglify = require('gulp-uglify');
var jshint = require('gulp-jshint');
var replace = require('gulp-replace');
var less = require('gulp-less');
var minifyCSS = require('gulp-minify-css');
var del = require('del');

/************************************************************
 * Extend elixir to make use of the del() command
 ***********************************************************/
// Add the delete task
elixir.extend("remove", function(path) {
	new elixir.Task("remove", function() {
		del(path);
	});
});

/**
 * We use elixir to work on our things.
 * Laravel style, baby
 */

elixir(function (mix) {
	// Clean JS and CSS directories
	mix.remove(['public/css', 'public/js']);

	// Build CSS files
	mix.less(
		'../../Private/Stylesheets/Forger.less',
		'public/css/forger.css'
	);

	// Build JS
	mix.scripts(
		[
			'../../../node_modules/jquery/dist/jquery.js',
			'../../../node_modules/bootstrap/dist/js/bootstrap.js',
			'../../Private/Javascripts/forger.js'
		],
		'public/js/forger.js'
	)
		//.scripts(['forum.js', 'threads.js'], 'public/js/forum.js');

	// Copy amcharts
	mix.copy(
		'node_modules/amcharts/dist/amcharts', 'public/js/amcharts'
	);

	// Copy fonts
	mix.copy(
		'node_modules/font-awesome/fonts', 'public/fonts'
	);
	mix.copy(
		'node_modules/font-awesome/css/font-awesome.min.css', 'public/css'
	);

	// Copy single JS files which don't work on their own
	mix.copy(
		[
			'resources/Private/Javascripts/forger.Rst.js',
			'resources/Private/Javascripts/sprintf.min.js',
			'resources/Private/Javascripts/register-element.js',
			'resources/Private/Javascripts/time-elements.js'
		],
		'public/js'
	);
});