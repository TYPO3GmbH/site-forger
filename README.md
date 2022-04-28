## ForgerNG

Next generation of forger

## Developer Start-Up-Guide

### Configuration

* clone repository
* call `ddev start`
* put your personal redmine access token into `.env.local` with the key `FORGE_ACCESS_TOKEN`
* call `ddev composer install`
* call `ddev exec /usr/bin/elasticorn.php index:init -c /var/www/html/config/Elasticorn` to create indices
* call `ddev exec npm ci`
* call `ddev exec npm run build`
* call `ddev exec bin/console doctrine:schema:create`

### Add inline scripts to aggregations in elasticsearch

script.engine.groovy.inline.aggs: on in elasticsearch.yml

## Imports

Forger allows the following commands to be used on CLI:

* `ddev exec bin/console app:import --mode=forgeFull` will import Redmine completely
* `ddev exec bin/console app:import --mode=forgeDelta` will import the latest 100 issues from Redmine
* `ddev exec bin/console app:import --mode=reviewFull` will import Gerrit completely
* `ddev exec bin/console app:import --mode=reviewDelta` will import the latest 100 Gerrit changes and create interactions from them
* `ddev exec bin/console app:import --mode=github --organization=TYPO3-Documentation` will import the given GitHub Organization and create interactions
* `ddev exec bin/console app:import --mode=githubDelta --organization=TYPO3-Documentation` will import the latest 100 GitHub changes and create interactions
* `ddev exec bin/console app:import --mode=githubDelta --organization=TYPO3 --repository=TYPO3.Icons` will import the latest 100 GitHub changes for the specified repository and create interactions

Using `--sendnotifications` on the commands above will trigger Discord notifications.

* `ddev exec bin/console app:truncate-type` truncates a given type and takes the following arguments:
  * `ddev exec bin/console app:truncate-type issue` to delete all issues
  * `ddev exec bin/console app:truncate-type review` to delete all reviews
  * `ddev exec bin/console app:truncate-type interactions` to delete all interactions
  * `ddev exec bin/console app:truncate-type karmapoints` to delete all karma points (BE CAREFUL WITH THIS!)
* `ddev exec bin/console app:calculate-karma` to calculate karmapoints (based on the year set in arguments)
  * argument `year` (int) `ddev exec bin/console app:calculate-karma 2019` to select the year to update
  * option `--account` (string) `ddev exec bin/console app:calculate-karma 2019 --account=dermattes` to define a dedicated account to update
* `ddev exec bin/console app:backport` will populate the backport view

Note that for import Discord messages to be sent you need the `DISCORD_ISSUES_WEBHOOK` env variable.

## Keycloak

Authentication is done via Keycloak/Gatekeeper.

Following user groups are taken into consideration:

* typo3.com-gmbh -> ROLE_ADMIN
* typo3.org.team.core.component -> ROLE_CORE_COMPONENT
* typo3.org.team.core.framework -> ROLE_CORE_FRAMEWORK

