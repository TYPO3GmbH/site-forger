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

### Use Elasticorn to set up the indices

Config files are located in `laravel/config/Elasticorn/t3_forger`

## Imports

Forger allows the following commands to be used on CLI:

* php artisan Forger:Import GerritTest

  Shows the last review from Gerrit. Does **not** import data.

* php artisan Forger:Import RedmineTest

  Imports issue #63618 into Elasticsearch

* php artisan Forger:Import SlackTest

  Links issue #63618 into the official Slack channel

* php artisan Forger:Import RedmineDelta

  Imports 50 issues into elasticsearch based on their last update

* php artisan Forger:Import RedmineFull

  Imports **all** issues from redmine into elasticsearch. beware, this can take up to 10 hours. If Redmine does not answer in between the import is stopped. You can optionally add the `--startAt=[INTEGER]`` parameter to start at a certain state of the import.

* php artisan Forger:Import GerritDelta

  Imports the latest 35 reviews from Gerrit into Elasticsearch

* php artisan Forger:Import GerritFull

  Imports **all** reviews from Gerrit into Elasticsearch. This normally takes about 10 minutes.

* php artisan Forger:Import Users

  Imports all **members** of the core project on redmine into elasticsearch.