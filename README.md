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
| `forgeDelta` | The Forge issues changed since the last import | The schedule, every 5 minutes |
| `reviewFull [--force]` | Every Gerrit change of `Packages/TYPO3.CMS` | An empty index, a weekly safety net |
| `reviewDelta` | The Gerrit changes updated since the last import | The schedule, every 5 minutes |

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

The schedule knows the lock of each job (`App\Scheduler\JobSchedule::JOBS`) and does not start a job whose lock
another one holds: a delta during its full import waits in the queue and starts once the lock is free, the command
runs page shows "waits for" the job holding it, and the waiting job is not overdue meanwhile.

### Errors and the command runs page

Every run of an `app:*` command is recorded in the table `command_run`, whoever starts it (the schedule, a deployment,
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
| Interrupted | Stopped for a pause, e.g. a deployment; it starts again once resumed |
| Cancelled | Stopped by an admin, it does not start again |
| Running | Running, with a heartbeat at least every 15 seconds |

The page shows the jobs of the schedule only (`App\Scheduler\ScheduledJobs` reads them from the schedule); other
runs, like `app:elasticsearch:setup` of a deployment, are recorded but not shown. **Next runs** lists what starts
next: the jobs queued with **Run now** (the worker of the schedule starts them within a minute, the web request
does not run a command itself), then the next planned run of every job. Every job of the schedule shows when it
runs and its next run. A job of the schedule is **overdue** when the run
planned after its last start did not start within 10 minutes (cron no longer starts the worker), or three planned
runs in a row did not succeed (every run fails). Runs skipped for a lock count as fine for up to 12 hours. This
holds for the weekly jobs too: a missing Monday run of `reviewFull` shows. A job the schedule does not start (a
deployment, a run by hand) is never overdue.

Rate limits are retried: Forge and Gerrit answer 429 now and then. Forge is retried up to six times with a
pause doubling from 10 seconds to 5 minutes, Gerrit up to four times, honouring `Retry-After`.

The table stays small on its own: one row per run, runs older than 14 days and all but the latest 500 runs of a
job are deleted when a run starts, the message is cut at 2000 characters.

What the page cannot show: a run that ends before the console starts (wrong PHP binary, a broken
`.env.local.php`, the database down) leaves no row. The job then shows as overdue, or does not appear at all.

### Schedule and cron

When the jobs run is part of the app, in `App\Scheduler\JobSchedule` (Symfony Scheduler):

| Job | When (server time) |
|---|---|
| `app:import --mode=forgeDelta` | every 5 minutes |
| `app:import --mode=reviewDelta` | every 5 minutes |
| `app:backports` | every 5 minutes |
| `app:import --mode=reviewFull` | Monday 03:17 |
| `app:import --mode=forgeFull` | Thursday 03:17 |

`bin/console debug:scheduler` lists them with their next run. Cron only starts the worker of the schedule, once a
minute for less than a minute, with a file in `/etc/cron.d` on the server (not part of this repository):

```cron
# m  h  dom mon dow user              command
*    *  *   *   *   forger.typo3.com  /usr/bin/php8.5 /srv/vhosts/forger.typo3.com/site/mage/current/bin/console messenger:consume scheduler_jobs --time-limit=55 > /srv/vhosts/forger.typo3.com/site/mage/shared/var/log/scheduler.lastrun 2>&1
```

The worker starts a due job as a process of its own and does not wait for it: a `reviewFull` running for hours
keeps no delta waiting, the locks of the imports keep two runs of one kind apart. The schedule remembers what it
started (`var/scheduler`, kept by the deployments): a job that fell due while no worker ran, e.g. during a
deployment, runs once when the next worker starts. The output of a job goes to `var/log/jobs/<job>.lastrun`.

#### Pause, resume, cancel

Every job of the schedule can be paused, alone or all of them (`App\Scheduler\JobControl`): on the command runs
page (**Pause all**, **Pause** and **Resume** at every job, **Pause** and **Cancel** at a running job) or with

```sh
bin/console app:jobs:pause [<job>] [--reason=...] [--wait=300]   # all jobs without <job>, e.g. forgeDelta
bin/console app:jobs:resume [<job>]
```

A paused job does not start, its planned starts wait in the queue. A running job asked to stop stops at its next
heartbeat (within about 15 seconds; one waiting for an answer of Forge or Gerrit takes until then) and releases its
lock: paused, it ends as "Interrupted" and is queued, to start again once resumed; cancelled, it ends as
"Cancelled" and stays so. Starting again costs little: a delta goes on from the index, a full import skips what it
has in its current state. `app:jobs:pause` waits for the running jobs to stop, at most `--wait` seconds.

Before the new release goes live, a deployment compiles its environment, migrates the database and pauses all jobs
for the setup of the indices (`forger:prepare_release` in `deploy.php`). It resumes them once the new release is live
and its cache warmed up, or with the new release's console if the deployment failed: no import
writes while the indices are rebuilt, and the interrupted jobs start again with the new code and schema. The
migrations run while the current release is still live, so a migration has to keep the schema usable for it: add
tables and columns, do not rename or drop them in the same deployment.

#### Locally

ddev runs no cron and no worker: locally the schedule starts nothing until you start its worker. The jobs then read
the real Forge and Gerrit into the local indices, `app:mock-data` brings back the mock documents afterwards.

```sh
# The jobs with their next run
ddev exec bin/console debug:scheduler
# The worker as cron starts it: starts the due jobs and those queued with "Run now", then stops
ddev exec bin/console messenger:consume scheduler_jobs --time-limit=55 -vv
# Or keep it running in a terminal, until Ctrl+C
ddev exec bin/console messenger:consume scheduler_jobs -vv
```

A worker keeps the code it started with: after a change, stop it and start it again (cron does that every minute).
A job queued with "Run now" starts at the next full minute, unless its lock is taken (a delta of the same source runs or
is planned for this minute): then it waits in the queue and starts once the lock is free.

The worker logs every job it starts (`Started the job "forgeDelta"`), the output of a job is in
`var/log/jobs/<job>.lastrun`, its run on the command runs page (`/management/command-runs`, as Dev Admin via
`/_dev/login`). To see a missed run catch up: let a five-minute mark pass without a worker, then start it, the
deltas run once. `rm -rf var/scheduler` forgets what the schedule started. The tests of the schedule:
`bin/phpunit -c build/phpunit.xml tests/Scheduler`.

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

