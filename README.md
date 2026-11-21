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
7. `ddev exec bin/console app:import:forge` to import the latest Forge issues (on an empty index the latest 100,
   `--full` imports all of them), see [Imports](#imports)
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
| `FORGE_ACCESS_TOKEN` | `app:import:forge` and `app:import:users`. Works without, but anonymous requests hit Forge's rate limit quickly. | Log in at [forge.typo3.org](https://forge.typo3.org), open [My account](https://forge.typo3.org/my/account) and click **Show** below **API access key** in the sidebar. |

The Gerrit import (`app:import:review`) reads the public API of review.typo3.org and need no token.

## Imports

The import commands read Forge (Redmine) and Gerrit into Elasticsearch. Prefix them with `ddev exec` in the dev
environment.

| Command | Reads | Meant for |
|---|---|---|
| `app:import:forge` | The Forge issues changed since the last import | The schedule, every 5 minutes |
| `app:import:forge --full` | Every Forge issue of the TYPO3 Core project | An empty index, by hand |
| `app:import:review` | The Gerrit changes updated since the last import | The schedule, every 5 minutes |
| `app:import:review --full` | Every Gerrit change of `Packages/TYPO3.CMS` | An empty index, by hand |
| `app:import:users` | Some users that are due | The schedule, every 5 minutes |

`--start-at=<n>` lets a full import begin at the n-th issue or change. The full imports are no jobs of the schedule:
the deltas keep the index complete on their own, see [How the deltas work](#how-the-deltas-work).

All three commands also take numbers. They load these issues, changes or users again, and remove the ones the source
does not have any more. With `--remove` they remove them from the index:

```sh
bin/console app:import:forge 91089 91090
bin/console app:import:review 95026 --remove
bin/console app:import:users 320
```

Other commands:

* `app:backports` writes the list of the backport view (`var/backports.json`), for the releases of the
  parameter `backport_releases`
* `app:truncate-type <issue|review>` deletes all documents of an index type
* `app:elasticsearch:setup`, see [Elasticsearch indices](#elasticsearch-indices)

### The project

Forge lists the issues of subprojects with those of a project. The import keeps the issues of the project itself
(`redmine_project_id`, 27) only (`App\Import\ProjectIssues`), so the figures match the project on Forge. Every
Forge import removes issues of other projects from the index, e.g. after an issue was moved. Issues Forge does not
list any more are asked for in batches of 100 (`App\Import\ForgeIssueList`), those it does not list for the index
then (deleted, private, in another project) are removed.

Forger shows only what Gerrit shows to everyone, the Gerrit imports ask without an account. A refresh of a change
Gerrit does not show any more (private now, deleted) removes it, and so does a complete `app:import:review --full` for the changes
Gerrit did not list (`App\Import\ProjectChanges`): it asks Gerrit for them again first.

### How the deltas work

The deltas only read what changed, and only load details for what they do not have yet:

* **Where to start:** the latest `updated_on` in the index, minus an overlap of 10 minutes
  (`App\Import\ImportWatermark`). The index is the state of the import: after an outage, a failed or a
  skipped run, the next run catches up on everything changed since. There is no state to keep elsewhere.
* **Forge:** lists the issues with `updated_on>=<start>`, the oldest first, and skips those the index has
  with the same `updated_on`. Only changed issues are written, and only for those under review the journals
  are loaded. A run ends after 2000 issues and reports a warning, the next run goes on from there.
* **Gerrit:** a light query lists the changes updated since the start (`after:`) with their update time,
  the details (votes, the current patch set with its files and commit) are loaded only for the changes the index does not have in this
  state. Gerrit lists the newest first, so the changes are written once all of them are loaded: a run that
  fails half way leaves the index as it was, and the next run tries again. Beyond 3000 updated changes the
  newest would move the start past the older ones: the run takes the oldest window from the start that fits
  instead (a day, halved until it fits, `ImportWatermark::catchUpWindow()`), notes it, and the next runs go on.
* **Forge, what no list of changes shows:** after its changes, a Forge delta that is up to date compares the
  number of issues with Forge, in one request (`App\Import\IssueReconciliation`): Forge counts the issues of the
  project, not private, as the index holds them. Where the numbers differ the range of numbers is halved, one
  count each, until a part holds a page; it is listed, the issues missing or not up to date are imported, those
  Forge does not list are removed. At most 15 counts per run, the next run goes on. So issues deleted on Forge,
  private now, or never imported get in line without a full import.
* **Authors and assignees:** the issues keep only their number and name, as Forge lists them. The rest is in
  the index `user`, see [The users](#the-users).
* **Possible duplicates:** last, the Forge import checks the open issues for duplicates
  (`App\Import\DuplicateImport`) and keeps the pairs in the index `duplicate` for the list "Possible duplicates".
  It asks Forge nothing. It checks the issues changed since their last check and the new ones, at most 200 per
  run, and removes those no longer open. The similarity is the same from both sides, so a new issue finds its
  pairs with the older ones by itself. The rest of the 200 renews checks older than a week. On an empty index
  the first runs check all open issues, a share each.

On an empty index the deltas import the latest 100 issues or changes and report a
warning: run the full import once, by hand. The comparison with Forge would fill it too, but a page at a time.

### The users

The index `user` holds the people of Forge and Gerrit, one document per TYPO3 account (`App\Repository\UserRepository`,
`App\People\User`): the username, the number on Forge (`forge_id`) and on Gerrit (`gerrit_id`), the name, the known
emails. Forge and Gerrit both log in with the TYPO3 account: the Forge login and the Gerrit username
are its username, the id of the document. A person of Forge is `forge:<forge_id>` until Forge told it.

**Gerrit** tells all of the owner of a change with the change (`DETAILED_ACCOUNTS`): number, name, username and
email. The import of the changes writes the owners the index `user` lacks at once (`UserRepository::ensureGerrit()`),
and adds the number on Gerrit to a person it has by the username. When Forge tells the username of a person later,
"Update users" joins the two documents. A change shows its owner, who uploaded it, with the account of Gerrit
(`owner_id`, `owner_username`), not the author of its commit, who has no account.

Forge tells more than the number and the name of a user only user by user, one request each: it lists users to its
admins only. So the imports of Forge do not wait for the users. The job "Update users" (`app:import:users`)
runs every five minutes with a lock of its own, so it does not wait for the Forge import. Both share the rate
limit of Forge, because the limiter keeps its state in the cache. Its name
says what it keeps, not where from: the source of the users is Forge for now (`App\Import\ForgeUserSync`), and
may be another one later under the same command:

* **The importer** writes the author and the assignee of every issue it writes to the index `user`, if it lacks
  them: empty but for the number and the name (`UserRepository::ensure()`). The job fills them.
* **First** the job takes the authors and assignees of the issues already in the index over that the index `user`
  lacks, without a request, with the email the import kept there before the index `user` existed. So the index
  `user` gets complete again by itself, also when it was lost.
* **Then** it asks Forge for 30 users (`--limit`): those never asked for, the ones without an email first, then
  those asked for more than 30 days ago.
  A rate limit ends the run, it does not wait.

The avatars are Gravatar's, by the first email the index `user` knows of the number on Forge or Gerrit, cached for
10 minutes: the same avatar for a person on both. Without an email, as of users who hide it on Forge and have no
account on Gerrit, the placeholder of Gravatar. `user_avatar()` and the macros of `templates/_macros/user.html.twig`
render a person of Forge or Gerrit alike; changes imported before the owner's account show the avatar of the email. "My Work" finds the
issues and changes by the numbers of the account on Forge and Gerrit: by its username, else by its email.
The API (see [API](#api)) serves only issues and changes, the index `user` holds email addresses.

### How the full imports work

The full imports list every issue or change, but load and write only those the index does not have
in their current state (missing, another `updated_on`, or written by an older version of the import), the same
comparison as the deltas: Forge lists the issues newest first, Gerrit is asked in two steps like the delta, and the changes are written batch by batch.
So a full import costs little more than the listing.
A full import that ended early is simply started again: what it already wrote is skipped.

### Versions of the import

A document has to be loaded again when its issue or change changed, and when the import takes more or other data
from Forge or Gerrit than when it wrote the document. Every document stores the version of the import that wrote it,
in `import_schema`, one version for the issues and one for the reviews (`App\Import\ImportSchema`); documents
written before the versions count as version 1.

* **Raise the version** of an index when its importer reads more or other data from the source or derives fields
  differently (`ForgeIssueImporter::document()`, `GerritChangeImporter::documents()`), and note at the constant
  what it brings.
  A change of the mapping alone needs no new version: `app:elasticsearch:setup` copies the documents over.
* **The deltas load them again over time:** after its changes, each delta loads up to 300
  documents of an older version again, those changed last first, and asks the source only for them (Forge by
  `issue_id`, Gerrit by `change:`). Three or twelve requests every five minutes: all of them in about a day, with
  no load peak. Those the source does not show any more are removed, so they are not chosen again in every run.
  The runs page notes how many are left.
* **Any import that meets one** loads it again, too: a document of an older version counts as changed.

### Catching up, e.g. over night

The deltas only go forward from the latest change in the index. To bring back what was missed before (an import
that was down, the former limit of 100 per run), run the full imports once, best over night. On the server:

```sh
cd /srv/vhosts/forger.typo3.com/site/mage/current
nohup sh -c '
    /usr/bin/php8.5 bin/console app:import:forge --full
    /usr/bin/php8.5 bin/console app:import:review --full
' > ../shared/var/log/catch-up.log 2>&1 &
```

The imports run one after the other, each also if the one before failed. Progress and result are on the command
runs page, the deltas meanwhile end as "Skipped, locked". Do not deploy during the run: the release it runs from
could be removed.

### Locks

Every import holds a lock, so two runs of the same kind do not write at the same time: `forge` (`app:import:forge`
and `app:import:users`) and `gerrit` (`app:import:review`, a full import blocks the delta for its run). A run that finds the lock
taken ends right away as "Skipped, locked". The lock goes with the process holding it.

The locks lie in `var/lock` of the installation (`config/packages/lock.yaml`), the lock of the schedule too:
`var` is kept by the deployments, so a lock holds across releases, and stage and live on the same server do not
lock each other, as they would with the temporary directory or the semaphores of the server. A job the worker
starts gets none of its open files (`App\Scheduler\StartJobHandler`): it would keep the lock of the schedule
otherwise, and the next worker would process no schedule until the job ends.

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
planned after its last start did not start within 10 minutes (the worker of the schedule does not run), or three planned
runs in a row did not succeed (every run fails). Runs skipped for a lock count as fine for up to 12 hours. A job the schedule does not start (a
deployment, a run by hand) is never overdue.

Rate limits are retried: Forge and Gerrit answer 429 now and then. Forge is retried up to six times with a
pause doubling from 10 seconds to 5 minutes, Gerrit up to four times, honouring `Retry-After`.

The table stays small on its own: one row per run, runs older than 14 days and all but the latest 500 runs of a
job are deleted when a run starts, the message is cut at 2000 characters.

What the page cannot show: a run that ends before the console starts (wrong PHP binary, a broken
`.env.local.php`, the database down) leaves no row. The job then shows as overdue, or does not appear at all.

### Schedule and worker

When the jobs run is part of the app, in `App\Scheduler\JobSchedule` (Symfony Scheduler):

| Job | When (server time) |
|---|---|
| `app:import:forge` | every 5 minutes |
| `app:import:review` | every 5 minutes |
| `app:import:users` | every 5 minutes |
| `app:backports` | every 5 minutes |

`bin/console debug:scheduler` lists them with their next run. The server only runs the worker of the schedule,
`messenger:consume scheduler_jobs`, as a systemd service. The worker starts a due job as a process of its own and does
not wait for it: a long run keeps no other job waiting, the locks of the imports keep two runs of one kind apart. The schedule remembers what it started (`var/scheduler`, kept by the deployments): a job that fell
due while no worker ran, e.g. during a restart or a deployment, runs once when the worker starts again. The output
of a job goes to `var/log/jobs/<job>.lastrun`, that of the worker to the journal.

#### The systemd service

The service is set up on the server, it is not part of this repository or of the deployment. Both hosts run on the
same server, under `/srv/vhosts/<vhost>/site/mage` (see `deploy.php`): one template unit serves both, its instance
is the vhost, whose user runs the worker. `/etc/systemd/system/forger-scheduler@.service`:

```ini
[Unit]
Description=Forger: worker of the job schedule of %i
After=network-online.target
Wants=network-online.target
# Never give up restarting, e.g. while the database is down for a while
StartLimitIntervalSec=0

[Service]
Type=simple
User=%i
# Resolved at each start: after a restart the worker runs the current release
WorkingDirectory=/srv/vhosts/%i/site/mage/current
# Ends itself after an hour or at 256 MB, and is started again: nothing builds up in a worker that runs for weeks
ExecStart=/usr/bin/php8.5 bin/console messenger:consume scheduler_jobs --time-limit=3600 --memory-limit=256M --no-interaction
Restart=always
RestartSec=5
# The jobs run as processes of their own (App\Scheduler\StartJobHandler): a restart or stop of the worker, the
# hourly one too, must not kill a running import. Only the worker itself is stopped.
KillMode=process
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now forger-scheduler@forger.typo3.com forger-scheduler@stage.forger.typo3.com
systemctl status 'forger-scheduler@*'
journalctl -u forger-scheduler@forger.typo3.com -f
```

The limits: `--time-limit=3600` and `--memory-limit=256M` end the worker after an hour or once it grew past 256 MB,
after the message at hand; `Restart=always` starts it again after 5 seconds, with the release `current` points to
then. A minute missed meanwhile runs when it starts. `KillMode=process` matters: systemd counts the jobs the worker
started as part of the service, and would kill a running import with every restart otherwise.

A worker keeps the code it started with. A deployment ends it once the new release is live and the jobs are resumed
(`forger:worker_restart` in `deploy.php`, `bin/console messenger:stop-workers` on the server): systemd starts it again
within seconds, with the new release, the running jobs go on. Without the service nothing restarts it, and a worker
someone started by hand stops.

The first deployment of the schedule replaces the cron jobs of the imports: comment them out in `/etc/cron.d`
before, wait until no import runs (the release before knows neither the pause nor the stop of a run), deploy, then
enable the service; the restart of the deployment finds no worker then, that does no harm. Not before: the release before does not know `scheduler_jobs`, the service would fail and restart
over and over. The changes imported before get the fields added with this release by themselves, see
[Versions of the import](#versions-of-the-import).

#### Pause, resume, cancel

Every job of the schedule can be paused, alone or all of them (`App\Scheduler\JobControl`): on the command runs
page (**Pause all**, **Pause** and **Resume** at every job, **Pause** and **Cancel** at a running job) or with

```sh
bin/console app:jobs:pause [<job>] [--reason=...] [--wait=300]   # all jobs without <job>, e.g. forgeDelta
bin/console app:jobs:resume [<job>]
```

A paused job does not start, its planned starts wait in the queue. A running job asked to stop stops within seconds,
also while it waits for a rate limit (it reads every 2 seconds whether it was asked to; one waiting for an answer of
Forge or Gerrit stops once it has it) and releases its lock: paused, it ends as "Interrupted" and is queued, to start again once resumed; cancelled, it ends as
"Cancelled" and stays so. Starting again costs little: a delta goes on from the index, a full import skips what it
has in its current state. `app:jobs:pause` waits for the running jobs to stop, at most `--wait` seconds.

Before the new release goes live, a deployment compiles its environment, migrates the database and pauses all jobs
for the setup of the indices (`forger:prepare_release` in `deploy.php`). It resumes them once the new release is live
and its cache warmed up, or with the new release's console if the deployment failed: no import
writes while the indices are rebuilt, and the interrupted jobs start again with the new code and schema. The
migrations run while the current release is still live, so a migration has to keep the schema usable for it: add
tables and columns, do not rename or drop them in the same deployment.

#### Locally

ddev runs no worker: locally the schedule starts nothing until you start it. The jobs then read
the real Forge and Gerrit into the local indices.

```sh
# The jobs with their next run
ddev exec bin/console debug:scheduler
# The worker for a minute: starts the due jobs and those queued with "Run now", then stops
ddev exec bin/console messenger:consume scheduler_jobs --time-limit=55 -vv
# Or keep it running in a terminal, until Ctrl+C
ddev exec bin/console messenger:consume scheduler_jobs -vv
```

A worker keeps the code it started with: after a change, stop it and start it again.
A job queued with "Run now" starts at the next full minute, unless its lock is taken (a delta of the same source runs or
is planned for this minute): then it waits in the queue and starts once the lock is free.

The worker logs every job it starts (`Started the job "forgeDelta"`), the output of a job is in
`var/log/jobs/<job>.lastrun`, its run on the command runs page (`/management/command-runs`, as Dev Admin via
`/_dev/login`). To see a missed run catch up: let a five-minute mark pass without a worker, then start it, the
deltas run once. `rm -rf var/scheduler` forgets what the schedule started. The tests of the schedule:
`bin/phpunit -c build/phpunit.xml tests/Scheduler`.

What happened is on the command runs page. The output of the last run of each job (`*.lastrun`, overwritten
by every run) is still worth keeping: it is the only trace of a run that fails before the console starts.
Whether the worker runs at all shows `systemctl status 'forger-scheduler@*'`, why it does not its journal.

## API

`/api/v1` is the public API: the issues and changes of the index as JSON, read only and without a login
(`App\Controller\ApiV1Controller`). It returns the objects in `App\Api\Model`, `App\Api\IssueMapper` and
`App\Api\ChangeMapper` build them from the documents. No email address leaves Forger.

The routes are stateless. `App\Subscriber\ApiRateLimitSubscriber` limits the requests of each address
(`public_api` in `config/packages/rate_limiter.yaml`) and marks the answers as public for a minute.
`App\Security\StatelessAwareKeycloakSubscriber` replaces the subscriber of the Keycloak bundle, which opens the
session on every request to refresh the token: on the API that would send a cookie and make every answer private.

The documentation is at `/api/doc`, linked as "API" in the footer, and the OpenAPI document at `/api/doc.json`.
NelmioApiDocBundle builds both from the attributes of the controller and the models.
`templates/bundles/NelmioApiDocBundle/SwaggerUi/index.html.twig` shows Swagger UI in the layout of Forger,
without its info block: the section "API" of the help explains how the API works (dates, pages, errors,
versions). `tests/Api/ApiDocumentationTest.php` checks that every endpoint and every field the API returns is
documented: describe a new field where you add it.

## Keycloak

Authentication is done via Keycloak (`t3g/symfony-keycloak-bundle`).

Following user groups are taken into consideration:

* typo3.com-gmbh -> ROLE_ADMIN
* typo3.org.team.core.component -> ROLE_CORE_COMPONENT
* typo3.org.team.core.framework -> ROLE_CORE_FRAMEWORK

