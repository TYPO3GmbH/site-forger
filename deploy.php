<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Deployer;

/*
 * Deployment of Forger, run by the "Deployment" job in .github/workflows/ci.yml:
 *
 *   build/tools/deployer/vendor/bin/dep deploy <master|develop>
 *
 * The recipe comes from TYPO3GmbH/infra-github-actions (deployer/README.md), checked out in .github/actions/t3g.
 */

require __DIR__ . '/.github/actions/t3g/deployer/recipe.php';

set('php_version', '8.5');

// What the releases share in var: the logs, the locks of the imports and of the schedule, the state of the
// schedule and the list of the backports. The cache is not shared: each release builds its own before it goes live,
// see forger:cache_warmup. A shared cache was removed while the live release and the worker wrote to it, and a
// release could start with the container of the release before.
set('shared_dirs', [
    'var/log',
    'var/lock',
    'var/scheduler',
]);
set('shared_files', [
    '.env.local',
    'var/backports.json',
]);

// The console of the new release before it goes live, for the tasks before its own cache is built. Its cache stays
// in the release and goes with it (deploy:cleanup), nothing else reads it. Environment and debug come from the
// .env.local.php of forger:dump_env.
set('release_console', 'APP_CACHE_DIR={{release_path}}/.deploy-cache {{bin/php}} {{release_path}}/bin/console --no-interaction');

// With the console of the release, not "composer dump-env": the Composer of the server does not load symfony/flex,
// which brings that command
desc('Compiles .env and the shared .env.local into .env.local.php of the new release');
task('forger:dump_env', function (): void {
    run('{{release_console}} dotenv:dump prod');
});

// The cache of the release in its own var/cache, with the environment of forger:dump_env: the release goes live with
// a complete cache, and no live process writes to it before
desc('Builds the cache of the new release before it goes live');
task('forger:cache_warmup', function (): void {
    run('{{bin/php}} {{release_path}}/bin/console cache:warmup --no-interaction', timeout: 900);
});

// The pause below needs the schema of the new release, so the migrations run while the current release is still
// live: a migration has to keep the schema usable for the current release, adding tables and columns, not renaming
// or dropping them in the same deployment.
desc('Migrates the database for the new release before it goes live');
task('forger:migrate', function (): void {
    run('{{release_console}} doctrine:migrations:migrate --allow-no-migration', timeout: 900);
});

// The jobs of the schedule pause for the deployment: none starts, the running ones stop and are queued, so the
// setup of the indices does not lose what an import writes meanwhile. See App\Scheduler\JobControl.
desc('Pauses the jobs of the schedule and waits for the running ones to stop');
task('forger:jobs_pause', function (): void {
    // Before: the pause is set first, then the command waits for the running jobs
    set('forger_jobs_paused', true);
    run('{{release_console}} app:jobs:pause --reason="a deployment" --wait=300', timeout: 600);
});

// Imports run the same health check, but the deployment applies configuration changes right away
desc('Brings the Elasticsearch indices in line with config/Elasticorn of the new release');
task('forger:elasticsearch_setup', function (): void {
    run('{{release_console}} app:elasticsearch:setup', timeout: 1800);
});

// Runs in the new release before it goes live, so a failure keeps the current release online
desc('Prepares the new release before it goes live');
task('forger:prepare_release', [
    'forger:dump_env',
    'forger:cache_warmup',
    'forger:migrate',
    'forger:jobs_pause',
    'forger:elasticsearch_setup',
]);
before('deploy:symlink', 'forger:prepare_release');

// The worker of the schedule runs as a systemd service on the server (see "The systemd service" in README.md): it ends
// after the message at hand, systemd starts it again within seconds, in the new release. The running jobs go on.
// The signal lies in var/scheduler, which the releases share. A release before that kept it in the shared var/cache
// gets the signal from its own console too: the worker may still run with it.
desc('Restarts the worker of the schedule with the new release');
task('forger:worker_restart', function (): void {
    run('{{bin/php}} {{current_path}}/bin/console messenger:stop-workers --no-interaction');
    if (has('previous_release')) {
        run('{{bin/php}} {{previous_release}}/bin/console messenger:stop-workers --no-interaction || true');
    }
});
after('deploy:symlink', 'forger:worker_restart');

// Once the new release is live and its worker runs, the queue starts the paused jobs within a minute. The worker
// restarts first, so no job of the release before starts after the pause.
desc('Resumes the jobs of the schedule');
task('forger:jobs_resume', function (): void {
    run('{{bin/php}} {{current_path}}/bin/console app:jobs:resume --no-interaction');
});
after('forger:worker_restart', 'forger:jobs_resume');

// With the release that paused them, its schema is migrated then. A deployment that failed before the pause has
// nothing to resume, and no console could: the database does not have the tables of the pause yet, or the release
// before does not know the command.
desc('Resumes the jobs of the schedule after a failed deployment');
task('forger:jobs_resume_failed', function (): void {
    if (!get('forger_jobs_paused', false)) {
        writeln('The jobs were not paused, nothing to resume');

        return;
    }
    run('{{release_console}} app:jobs:resume');
});
after('deploy:failed', 'forger:jobs_resume_failed');

// The host aliases match the branch names, the CI job deploys a branch to the host of the same name
host('master')
    ->setHostname('188.34.182.186')
    ->setRemoteUser('forger.typo3.com');

host('develop')
    ->setHostname('188.34.182.186')
    ->setRemoteUser('stage.forger.typo3.com');
