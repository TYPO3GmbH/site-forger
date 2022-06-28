<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use Elastica\Query\MatchAll;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use T3G\ElasticUtility\ElasticUtility;

class TruncateTypeCommand extends Command
{
    protected static $defaultName = 'app:truncate-type';

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Clears a type in the current index')
            ->addArgument('type', InputArgument::REQUIRED, 'Type to truncate. Allowed values are "review", "interactions" and "issue"')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getArgument('type');

        if ('review' === $type || 'issue' === $type || 'interactions' === $type || 'karmapoints' === $type) {
            $typeToWorkOn = ElasticUtility::getIndexByName($type);
            $query = new MatchAll();
            $typeToWorkOn->deleteByQuery($query);
            $io->success('Cleared type "' . $type . '"');
        } else {
            $io->error('Please provide a proper type to truncate. You provided "' . $type . '"');
        }
    }
}
