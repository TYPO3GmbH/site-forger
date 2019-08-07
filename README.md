## ForgerNG

Next generation of forger

## Developer Start-Up-Guide

### Configuration

* clone repository
* call `ddev start`
* put your personal redmine access token into `.env.local` with the key `FORGE_ACCESS_TOKEN`
* call `composer install`
* call `ddev ssh` to get into the container
  * change to `cd config/Elasticorn`
  * call `elasticorn.phar index:init -c /var/www/html/config/Elasticorn` to create indices

### Add inline scripts to aggregations in elasticsearch

script.engine.groovy.inline.aggs: on in elasticsearch.yml

## Imports

Forger allows the following commands to be used on CLI:

* `bin/console app:import --mode=forgeFull` will import Redmine completely
* `bin/console app:import --mode=forgeDelta` will import the latest 100 issues from Redmine
* `bin/console app:import --mode=reviewFull` will import Gerrit completely
* `bin/console app:import --mode=reviewDelta` will import the latest 100 Gerrit changes and create interactions from them
* `bin/console app:truncate-type` truncates a given type and takes the following arguments:
  * `bin/console app:truncate-type issue` to delete all issues
  * `bin/console app:truncate-type review` to delete all reviews
  * `bin/console app:truncate-type interactions` to delete all interactions
  
## LDAP

To use LDAP two variables are needed in the .env.local file. `LDAP_SEARCH_USER` and `LDAP_SEARCH_PASSWORD`.
