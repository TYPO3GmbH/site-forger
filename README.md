## ForgerNG

Next generation of forger

## Developer Start-Up-Guide

### Setup

Requirements: [DDEV](https://ddev.com). All Composer and npm dependencies are public,
no Packagist or GitHub credentials are needed to install them.

1. Clone the repository and run `ddev start`
2. Create `.env.local` with your personal access tokens (see [Access tokens](#access-tokens))
3. `ddev composer install`
4. `ddev exec npm ci`
5. `ddev exec npm run build`
6. `ddev exec bin/console doctrine:migrations:migrate`
7. `ddev exec bin/console app:import --mode=forgeDelta` to import the latest 100 Forge issues
8. Open https://forger.ddev.site

### Elasticsearch indices

The indices are configured in `config/Elasticorn` (settings and mapping per index type).
`bin/console app:elasticsearch:setup` brings Elasticsearch in line with this configuration: it creates
missing indices, rebuilds indices whose configuration changed (the documents are copied, the aliases are
switched afterwards) and takes over indices Elasticsearch created on its own. Use `--dry-run` to see what
it would do.

There is no separate setup step: every deployment runs it before the new release goes live, and every
import runs it as a health check before writing. When nothing changed, the check costs one request per index.

### Login in dev

In the dev environment, **Login** leads to https://forger.ddev.site/_dev/login. There you can
log in as one of the fixed users without a password, or with Keycloak (requires a valid
`KEYCLOAK_CLIENT_SECRET` in `.env.local`).

| User | Roles |
|---|---|
| `dev-admin` | `ROLE_ADMIN` |
| `dev-framework` | `ROLE_CORE_FRAMEWORK` |
| `dev-component` | `ROLE_CORE_COMPONENT` |
| `dev-user` | `ROLE_USER` |

The users are configured in `config/packages/security.yaml` (`when@dev`, parameter
`app.dev_login.users`). The dev login does not exist outside the dev environment.

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

Gerrit imports (`reviewFull`, `reviewDelta`) read the public API of review.typo3.org and need no token.

## Imports

Forger allows the following commands to be used on CLI:

* `ddev exec bin/console app:import --mode=forgeFull` will import Redmine completely
* `ddev exec bin/console app:import --mode=forgeDelta` will import the latest 100 issues from Redmine
* `ddev exec bin/console app:import --mode=reviewFull` will import Gerrit completely
* `ddev exec bin/console app:import --mode=reviewDelta` will import the latest 100 Gerrit changes and create interactions from them
* `ddev exec bin/console app:import --mode=github --organization=TYPO3-Documentation` will import the given GitHub Organization and create interactions
* `ddev exec bin/console app:import --mode=githubDelta --organization=TYPO3-Documentation` will import the latest 100 GitHub changes and create interactions
* `ddev exec bin/console app:import --mode=githubDelta --organization=TYPO3 --repository=TYPO3.Icons` will import the latest 100 GitHub changes for the specified repository and create interactions

* `ddev exec bin/console app:truncate-type` truncates a given type and takes the following arguments:
  * `ddev exec bin/console app:truncate-type issue` to delete all issues
  * `ddev exec bin/console app:truncate-type review` to delete all reviews
  * `ddev exec bin/console app:truncate-type interactions` to delete all interactions
* `ddev exec bin/console app:backport` will populate the backport view

## Keycloak

Authentication is done via Keycloak/Gatekeeper.

Following user groups are taken into consideration:

* typo3.com-gmbh -> ROLE_ADMIN
* typo3.org.team.core.component -> ROLE_CORE_COMPONENT
* typo3.org.team.core.framework -> ROLE_CORE_FRAMEWORK

