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

// The host aliases match the branch names, the CI job deploys a branch to the host of the same name
host('master')
    ->setHostname('188.34.182.186')
    ->setRemoteUser('forger.typo3.com');

host('develop')
    ->setHostname('188.34.182.186')
    ->setRemoteUser('stage.forger.typo3.com');
