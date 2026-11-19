<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Elastic\IndexSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:elasticsearch:setup',
    description: 'Creates the Elasticsearch indices and applies mapping changes from config/Elasticorn',
    help: <<<'HELP'
        Runs on every deployment and before every import, and is safe to run repeatedly:

          * missing indices are created with the configured mapping
          * indices whose mapping differs from the configuration are rebuilt, the documents are copied
          * indices Elasticsearch created on its own (dynamic mapping) are replaced, the documents are copied

        With <info>--dry-run</info> nothing is changed (a temporary "<type>_<language>_probe" index is used to compare mappings).
        HELP
)]
final readonly class ElasticsearchSetupCommand
{
    public function __construct(
        private IndexSetup $indexSetup,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only show what would be done')]
        bool $dryRun = false,
    ): int {
        $rows = [];
        if ($dryRun) {
            foreach ($this->indexSetup->planAll() as $name => $action) {
                $rows[] = [$name, $action, '-'];
            }
        } else {
            foreach ($this->indexSetup->ensureAll() as $name => $result) {
                $rows[] = [$name, $result['action'], (string) $result['documents']];
            }
        }

        $io->table(['Index', $dryRun ? 'Would be' : 'Result', 'Documents'], $rows);
        $dryRun ? $io->note('Dry run, nothing was changed.') : $io->success('Elasticsearch indices are up to date.');

        return Command::SUCCESS;
    }
}
