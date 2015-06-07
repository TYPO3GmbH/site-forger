var elixir = require('laravel-elixir');

/*
 |--------------------------------------------------------------------------
 | Elixir Asset Management
 |--------------------------------------------------------------------------
 |
 | Elixir provides a clean, fluent API for defining some basic Gulp tasks
 | for your Laravel application. By default, we are compiling the Less
 | file for our application, as well as publishing vendor resources.
 |
 */

elixir(function(mix) {
    mix.less('app.less');
});


var gulp = require('gulp');
var uglify = require('gulp-uglify');
var jshint = require('gulp-jshint');
var replace = require('gulp-replace');
var less = require('gulp-less');
var minifyCSS = require('gulp-minify-css');
var del = require('del');
/**
 * default task
 */
gulp.task('default', ['watch']);

/**
 * watcher task
 */
gulp.task('watch', function() {
	gulp.watch('resources/assets/less/**/*.less', ['less']);
	gulp.watch('resources/js/app/**/*.js', ['build']);
});

/**
 * build JS for Widget
 */
gulp.task('build', function() {

	// copy jquery
	gulp.src(['bower_components/jquery/dist/jquery.min.js'])
		.pipe(gulp.dest('public/js'));

	// copy bootstrap
	gulp.src(['bower_components/bootstrap/dist/js/bootstrap.min.js'])
		.pipe(gulp.dest('public/js'));

	// copy amcharts
	gulp.src(['bower_components/amcharts/dist/amcharts/**/*.*'])
		.pipe(gulp.dest('public/js/amcharts'));

	// copy fonts
	gulp.src(['bower_components/fontawesome/fonts/**/*.*'])
		.pipe(gulp.dest('public/fonts'));

});

/**
 * build CSS
 */
gulp.task('less', function() {
	del('public/css/app.css');

	gulp.src(['resources/assets/less/app.less'])
		.pipe(less())
		.pipe(minifyCSS())
		.pipe(gulp.dest('public/css'));
});
