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

set('shared_dirs', [
    'var',
]);
set('shared_files', [
    '.env.local',
]);

// Run in the new release once it is live. The environment is compiled and the database migrated before already,
// see forger:prepare_release.
set('post_release_commands', [
    'rm -rf var/cache',
    '{{bin/php}} ./bin/console cache:warmup',
]);

// The console of the new release before it goes live. It has a cache of its own: the shared var/cache still belongs
// to the live release. Environment and debug come from the .env.local.php of forger:dump_env. The cache stays in the
// release and goes with it (deploy:cleanup), nothing else reads it.
set('release_console', 'APP_CACHE_DIR={{release_path}}/.deploy-cache {{bin/php}} {{release_path}}/bin/console --no-interaction');

desc('Compiles .env and the shared .env.local into .env.local.php of the new release');
task('forger:dump_env', function (): void {
    run('cd {{release_path}} && {{bin/php}} /usr/bin/composer dump-env prod');
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
    'forger:migrate',
    'forger:jobs_pause',
    'forger:elasticsearch_setup',
]);
before('deploy:symlink', 'forger:prepare_release');

// Once the new release is live and its post release commands ran, the queue starts the paused jobs within a minute
desc('Resumes the jobs of the schedule');
task('forger:jobs_resume', function (): void {
    run('{{bin/php}} {{current_path}}/bin/console app:jobs:resume --no-interaction');
});
after('deploy:post_release', 'forger:jobs_resume');

// With the release that paused them; the current one is the old release then, it may not know the command
desc('Resumes the jobs of the schedule after a failed deployment');
task('forger:jobs_resume_failed', function (): void {
    run('{{release_console}} app:jobs:resume || {{bin/php}} {{current_path}}/bin/console app:jobs:resume --no-interaction');
});
after('deploy:failed', 'forger:jobs_resume_failed');

// The host aliases match the branch names, the CI job deploys a branch to the host of the same name
host('master')
    ->setHostname('188.34.182.186')
    ->setRemoteUser('forger.typo3.com');

host('develop')
    ->setHostname('188.34.182.186')
    ->setRemoteUser('stage.forger.typo3.com');
