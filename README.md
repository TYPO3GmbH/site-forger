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
7. `ddev exec bin/console app:import --mode=forgeDelta` to import the latest Forge issues (on an empty index the
   latest 100, `--mode=forgeFull` imports all of them), see [Imports](#imports)
8. Open https://forger.ddev.site

### Elasticsearch indices

The indices are configured in `config/Elasticorn` (settings and mapping per index type).
`bin/console app:elasticsearch:setup` brings Elasticsearch in line with this configuration: it creates
missing indices, rebuilds indices whose configuration changed (the documents are copied, the aliases are
switched afterwards) and takes over indices Elasticsearch created on its own. Use `--dry-run` to see what
it would do.

To remove an index type, delete its directory and add the type to `removed_types` in
`config/Elasticorn/Elasticorn.yaml`. The next run deletes its indices in every environment; the type can
leave the list once all environments are deployed.

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

**My Work** (`/my`, in the user menu) lists the issues and Gerrit changes of the logged-in user, matched
by email address or name. The fixed users get `<identifier>@forger.ddev.site`; add an `email` to a user
to see the work of an address from your imported data.

### Access tokens

Put your personal tokens into `.env.local` (git-ignored, overrides `.env`):

```dotenv
FORGE_ACCESS_TOKEN=...
```

| Variable | Needed for | How to get it |
|---|---|---|
| `FORGE_ACCESS_TOKEN` | `forgeFull`, `forgeDelta` imports. Works without, but anonymous requests hit Forge's rate limit quickly. | Log in at [forge.typo3.org](https://forge.typo3.org), open [My account](https://forge.typo3.org/my/account) and click **Show** below **API access key** in the sidebar. |

Gerrit imports (`reviewFull`, `reviewDelta`) read the public API of review.typo3.org and need no token.

## Imports

`bin/console app:import --mode=<mode>` reads Forge (Redmine) and Gerrit into Elasticsearch.
Prefix the commands with `ddev exec` in the dev environment.

| Mode | Reads | Meant for |
|---|---|---|
| `forgeFull [--force]` | Every Forge issue of the TYPO3 Core project | An empty index, a weekly safety net |
| `forgeDelta` | The Forge issues changed since the last import | Cron, every few minutes |
| `reviewFull [--force]` | Every Gerrit change of `Packages/TYPO3.CMS` | An empty index, a weekly safety net |
| `reviewDelta` | The Gerrit changes updated since the last import | Cron, every few minutes |

An unknown mode ends with an error. `--startAt=<n>` lets a full import begin at the n-th issue or change.

Other commands:

* `app:backports` writes the list of the backport view (`var/backports.json`)
* `app:truncate-type <issue|review>` deletes all documents of an index type
* `app:elasticsearch:setup`, see [Elasticsearch indices](#elasticsearch-indices)

### The project

Forge lists the issues of subprojects with those of a project. The import keeps the issues of the project itself
(`redmine_project_id`, 27) only (`App\Elastic\ProjectIssues`), so the figures match the project on Forge. Every
Forge import removes issues of other projects from the index, e.g. after an issue was moved; after a complete
`forgeFull` the issues Forge did not list any more are checked one by one and removed if they are gone.

Forger shows only what Gerrit shows to everyone, the Gerrit imports ask without an account. A refresh of a change
Gerrit does not show any more (private now, deleted) removes it, and so does a complete `reviewFull` for the changes
Gerrit did not list (`App\Elastic\ProjectChanges`): it asks Gerrit for them again first.

### How the deltas work

The deltas only read what changed, and only load details for what they do not have yet:

* **Where to start:** the latest `updated_on` in the index, minus an overlap of 10 minutes
  (`App\Elastic\ImportWatermark`). The index is the state of the import: after an outage, a failed or a
  skipped run, the next run catches up on everything changed since. There is no state to keep elsewhere.
* **Forge:** lists the issues with `updated_on>=<start>`, the oldest first, and skips those the index has
  with the same `updated_on`. Only changed issues are written, and only for those under review the journals
  are loaded. A run ends after 2000 issues and reports a warning, the next run goes on from there.
* **Gerrit:** a light query lists the changes updated since the start (`after:`) with their update time,
  the details (votes, the current patch set with its files and commit) are loaded only for the changes the index does not have in this
  state. Gerrit lists the newest first, so the changes are written once all of them are loaded: a run that
  fails half way leaves the index as it was, and the next run tries again. Beyond 3000 updated changes the
  older ones are missing until the next `reviewFull`, the run reports a warning.
* **Authors:** the emails of Forge users are cached for a day.

On an empty index `forgeDelta` and `reviewDelta` import the latest 100 issues or changes and report a
warning: run the full import once.

### How the full imports work

`forgeFull` and `reviewFull` list every issue or change, but load and write only those the index does not have
in their current state (missing, or another `updated_on`), the same comparison as the deltas: Forge lists the
issues newest first, Gerrit is asked in two steps like the delta, and the changes are written batch by batch.
So a full import costs little more than the listing, and it is the safety net for whatever the deltas missed.
A full import that ended early is simply started again: what it already wrote is skipped.

`--force` imports every issue or change, also the up-to-date ones. It is needed after a change of what the
import stores (a new field, another mapping), as an unchanged issue would keep its old document otherwise.

### Catching up, e.g. over night

The deltas only go forward from the latest change in the index. To bring back what was missed before (an import
that was down, the former limit of 100 per run), run the full imports once, best over night. On the server:

```sh
cd /srv/vhosts/forger.typo3.com/site/mage/current
nohup sh -c '
    /usr/bin/php8.5 bin/console app:import --mode=forgeFull
    /usr/bin/php8.5 bin/console app:import --mode=reviewFull
' > ../shared/var/log/catch-up.log 2>&1 &
```

The imports run one after the other, each also if the one before failed. Progress and result are on the command
runs page, the deltas meanwhile end as "Skipped, locked". Do not deploy during the run: the release it runs from
could be removed.

### Locks

Every import holds a lock, so two runs of the same kind do not write at the same time: `forge` (both Forge
modes) and `gerrit` (both Gerrit modes, `reviewFull` blocks `reviewDelta` for its run). A run that finds the lock
taken ends right away as "Skipped, locked". The lock goes with the process holding it.

### Errors and the command runs page

Every run of an `app:*` command is recorded in the table `command_run`, whoever starts it (cron, a deployment,
by hand). Admins see the runs at **Admin → Command runs** (`/management/command-runs`):

* the jobs (command with its options) with their last runs, overdue ones first
* what is running now, with its phase and progress (refreshed every 15 seconds)
* the latest runs, filtered to the problems if wanted, with the error or the first warnings

| Status | Meaning |
|---|---|
| Success | The run did its work |
| Success with warnings | Single items were skipped, e.g. a Forge issue Forge did not answer for; the message lists the first of them |
| Skipped, locked | Another run held the lock |
| Failed | The run ended with an error: exception, exit code other than 0, Gerrit or Forge unavailable after the retries |
| Aborted | No end recorded and no heartbeat for 15 minutes: killed, out of memory, server restart |
| Running | Running, with a heartbeat at least every 15 seconds |

A job is **overdue** when its last start or its last successful run is more than three times its usual interval
ago (at least 15 minutes), the interval being learned from its runs: cron no longer starts it, or every run
fails. Runs skipped for a lock count as fine for up to 12 hours. A job needs three runs within the 14 days kept
before it has an interval, a weekly job never becomes overdue.

Rate limits are retried: Forge and Gerrit answer 429 now and then. Forge is retried up to six times with a
pause doubling from 10 seconds to 5 minutes, Gerrit up to four times, honouring `Retry-After`.

The table stays small on its own: one row per run, runs older than 14 days and all but the latest 500 runs of a
job are deleted when a run starts, the message is cut at 2000 characters.

What the page cannot show: a run that ends before the console starts (wrong PHP binary, a broken
`.env.local.php`, the database down) leaves no row. The job then shows as overdue, or does not appear at all.

### Cron

The imports on production are started by a file in `/etc/cron.d` on the server, it is not part of this
repository. A setup that keeps the data current:

```cron
# m  h  dom mon dow user              command
*/5  *  *   *   *   forger.typo3.com  /usr/bin/php8.5 /srv/vhosts/forger.typo3.com/site/mage/current/bin/console app:import --mode=forgeDelta > /srv/vhosts/forger.typo3.com/site/mage/shared/var/log/forgeimport_delta.lastrun 2>&1
*/5  *  *   *   *   forger.typo3.com  /usr/bin/php8.5 /srv/vhosts/forger.typo3.com/site/mage/current/bin/console app:import --mode=reviewDelta > /srv/vhosts/forger.typo3.com/site/mage/shared/var/log/forgeimport_gerritdelta.lastrun 2>&1
*/5  *  *   *   *   forger.typo3.com  /usr/bin/php8.5 /srv/vhosts/forger.typo3.com/site/mage/current/bin/console app:backports > /srv/vhosts/forger.typo3.com/site/mage/shared/var/log/forgeimport_backports.lastrun 2>&1
# Safety nets, on different nights so the rate limits of Forge and Gerrit do not add up
17   3  *   *   1   forger.typo3.com  /usr/bin/php8.5 /srv/vhosts/forger.typo3.com/site/mage/current/bin/console app:import --mode=reviewFull > /srv/vhosts/forger.typo3.com/site/mage/shared/var/log/forgeimport_fullgerrit.lastrun 2>&1
17   3  *   *   4   forger.typo3.com  /usr/bin/php8.5 /srv/vhosts/forger.typo3.com/site/mage/current/bin/console app:import --mode=forgeFull > /srv/vhosts/forger.typo3.com/site/mage/shared/var/log/forgeimport_fullforge.lastrun 2>&1
```

What happened is on the command runs page. The output of the last run of each job (`*.lastrun`, overwritten
by every run) is still worth keeping: it is the only trace of a run that fails before the console starts.
Pitfalls of `/etc/cron.d`: the
name of the file must not contain a dot (`forger.typo3.com` is ignored, `forger` works), and the last line
needs a line break. Whether cron starts the jobs at all shows the system log: `grep CRON /var/log/syslog`.

## Keycloak

Authentication is done via Keycloak (`t3g/symfony-keycloak-bundle`).

Following user groups are taken into consideration:

* typo3.com-gmbh -> ROLE_ADMIN
* typo3.org.team.core.component -> ROLE_CORE_COMPONENT
* typo3.org.team.core.framework -> ROLE_CORE_FRAMEWORK

