## ForgerNG

Next generation of forger

## Developer Start-Up-Guide

### Configuration

copy ``.env.example`` into ``.env`` and adjust your personal settings

~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
cd laraval
cp .env.example .env
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

### DocumentRoot

~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
laravel/public
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

### Development: Step-By-Step

~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
# clone the repo
git clone git@github.com:NeoBlack/ForgerNG.git

# change to laravel folder
cd laravel

# yeah, composer install all the stuff you need
composer install

# yeah, npm install all the stuff you need
npm install

# yeah, bower install all the stuff you need
bower install

# now you are ready, be happy ;)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

### Development: Notes

Within the project we use gulp.js for building JavaScript and CSS.
The project contains a ``gulpfile.js`` within the ``laraval`` folder.

A ``package.json`` is also available, so you can simple call ``npm install`` to get all packages.

*Do not edit the JavaScript or CSS files in public folder directly* All changes has to be done in the less files.

Please look into the ``gulpfile.js`` for details to the tasks.

### Add inline scripts to aggregations in elasticsearch

script.engine.groovy.inline.aggs: on in elasticsearch.yml
