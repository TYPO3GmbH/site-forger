## ForgerNG

Next generation of forger

## Developer Start-Up-Guide

### Setup

Requirements: [DDEV](https://ddev.com). All Composer and npm dependencies are public,
no Packagist or GitHub credentials are needed to install them.

1. Clone the repository and run `ddev start`
2. Create `.env.local` with your personal access tokens (see [Access tokens](#access-tokens))
3. `ddev composer install`
4. `ddev composer install --working-dir=./util`
5. `ddev exec ./util/bin/elasticorn.php index:init -c /var/www/html/config/Elasticorn` to create the Elasticsearch indices
6. `ddev exec npm ci`
7. `ddev exec npm run build`
8. `ddev exec bin/console doctrine:schema:create`
9. `ddev exec bin/console app:import --mode=forgeDelta` to import the latest 100 Forge issues
10. Open https://forger.ddev.site

Pages that require a login redirect to login.typo3.com and only work with a valid
`KEYCLOAK_CLIENT_SECRET`.

### Access tokens

Put your personal tokens into `.env.local` (git-ignored, overrides `.env`):

```dotenv
FORGE_ACCESS_TOKEN=...
GITHUB_ACCESS_TOKEN=...
```

| Variable | Needed for | How to get it |
|---|---|---|
| `FORGE_ACCESS_TOKEN` | `forgeFull`, `forgeDelta` imports. Works without, but anonymous requests hit Forge's rate limit quickly. | Log in at [forge.typo3.org](https://forge.typo3.org), open [My account](https://forge.typo3.org/my/account) and click **Show** below **API access key** in the sidebar. |
| `GITHUB_ACCESS_TOKEN` | `github`, `githubDelta` imports | Create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens/new) with repository access **Public repositories (read-only)**. No further permissions are needed. |
| `DISCORD_ISSUES_WEBHOOK` | Only for imports with `--sendnotifications` | Webhook URL of the Discord channel (Channel settings → Integrations → Webhooks). |

Gerrit imports (`reviewFull`, `reviewDelta`) read the public API of review.typo3.org and need no token.

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

