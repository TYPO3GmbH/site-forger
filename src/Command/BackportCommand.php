<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Report\Backport\BackportFile;
use App\Report\Backport\BackportFinder;
use App\Repository\BackportBlacklistEntryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:backports', description: 'Creates a list of available backports')]
class BackportCommand extends Command
{
    public function __construct(
        private readonly BackportFinder $finder,
        private readonly BackportFile $file,
        private readonly BackportBlacklistEntryRepository $blacklist,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->file->write($this->finder->find($this->blacklist->changeIds()));
        (new SymfonyStyle($input, $output))->success('Wrote backports to ' . $this->file->path());

        return Command::SUCCESS;
    }
}
