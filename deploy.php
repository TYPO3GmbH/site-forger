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

set('post_release_commands', [
    // Compiles .env and the shared .env.local into .env.local.php
    '{{bin/php}} /usr/bin/composer dump-env prod',
    'rm -rf var/cache',
    '{{bin/php}} ./bin/console doctrine:migrations:migrate --no-interaction',
    '{{bin/php}} ./bin/console cache:warmup',
]);

// Runs in the new release before it goes live, so a failure keeps the current release online.
// Imports run the same health check, but the deployment applies configuration changes right away.
desc('Brings the Elasticsearch indices in line with config/Elasticorn of the new release');
task('forger:elasticsearch_setup', function (): void {
    cd('{{release_path}}');
    // Own cache directory: the shared var/cache still belongs to the live release
    run('APP_ENV=prod APP_DEBUG=0 APP_CACHE_DIR={{release_path}}/.deploy-cache {{bin/php}} ./bin/console app:elasticsearch:setup --no-interaction', timeout: 1800);
    run('rm -rf {{release_path}}/.deploy-cache');
});
before('deploy:symlink', 'forger:elasticsearch_setup');

// The recipe runs the post release commands only after the symlink, the pause below needs the schema of the new
// release before. So the migrations run here already, while the current release is still live: a migration has to
// keep the schema usable for the current release, adding tables and columns, not renaming or dropping them in the
// same deployment. The migrate of the post release commands then finds nothing left to do.
desc('Migrates the database for the new release before it goes live');
task('forger:migrate', function (): void {
    cd('{{release_path}}');
    run('APP_ENV=prod APP_DEBUG=0 APP_CACHE_DIR={{release_path}}/.deploy-cache {{bin/php}} ./bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration', timeout: 900);
});
before('forger:jobs_pause', 'forger:migrate');

// The jobs of the schedule pause for the deployment: none starts, the running ones stop and are queued, so the
// setup of the indices does not lose what an import writes meanwhile. Resumed once the new release is live and its
// post release commands ran (environment, cache, migrations), or with the old one if the deployment fails, the queue
// starts them again within a minute. See App\Scheduler\JobControl.
desc('Pauses the jobs of the schedule and waits for the running ones to stop');
task('forger:jobs_pause', function (): void {
    cd('{{release_path}}');
    run('APP_ENV=prod APP_DEBUG=0 APP_CACHE_DIR={{release_path}}/.deploy-cache {{bin/php}} ./bin/console app:jobs:pause --reason="a deployment" --wait=300 --no-interaction', timeout: 600);
});
before('forger:elasticsearch_setup', 'forger:jobs_pause');

desc('Resumes the jobs of the schedule');
task('forger:jobs_resume', function (): void {
    // With the release that paused them, the old one may not know the command, after a failed deployment as well
    run('cd {{release_path}} && APP_ENV=prod APP_DEBUG=0 APP_CACHE_DIR={{release_path}}/.deploy-cache {{bin/php}} ./bin/console app:jobs:resume --no-interaction'
        . ' || (cd {{current_path}} && {{bin/php}} ./bin/console app:jobs:resume --no-interaction)');
    run('rm -rf {{release_path}}/.deploy-cache');
});
after('deploy:post_release', 'forger:jobs_resume');
after('deploy:failed', 'forger:jobs_resume');

// The host aliases match the branch names, the CI job deploys a branch to the host of the same name
host('master')
    ->setHostname('188.34.182.186')
    ->setRemoteUser('forger.typo3.com');

host('develop')
    ->setHostname('188.34.182.186')
    ->setRemoteUser('stage.forger.typo3.com');
