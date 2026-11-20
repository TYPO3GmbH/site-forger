<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Elastic\ElasticUtility;
use App\Elastic\IndexSetup;
use App\Mock\MockDataGenerator;
use App\Security\Dev\DevUserRegistry;
use Elastica\Document;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Fills the local indices with mock issues and Gerrit changes in every state.
 * They belong to the dev login users, so "My Work" shows all of them.
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:mock-data', description: 'Replaces the mock issues and Gerrit changes in the local indices (dev only)')]
final class MockDataCommand extends Command
{
    /**
     * People besides the dev users: a mixed-case address and an author Forge hides the address of.
     */
    private const array EXTERNAL_PEOPLE = [
        ['name' => 'Erika Mustermann', 'email' => 'Erika.Mustermann@Example.org', 'account' => 'erika'],
        ['name' => 'Max Mock', 'email' => '', 'account' => 'maxmock'],
    ];

    public function __construct(
        private readonly DevUserRegistry $devUserRegistry,
        private readonly IndexSetup $indexSetup,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('remove', null, InputOption::VALUE_NONE, 'Only remove the mock documents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->indexSetup->ensureAll();

        $this->remove();
        if ($input->getOption('remove')) {
            $io->success('Removed the mock documents.');

            return Command::SUCCESS;
        }

        $generator = new MockDataGenerator($this->people(), new \DateTimeImmutable());
        $counts = [
            'issue' => $this->write('issue', $generator->issues(), static fn (array $doc): string => (string) $doc['id']),
            'review' => $this->write('review', $generator->reviews(), static fn (array $doc): string => (string) $doc['id']),
        ];

        $io->success(sprintf(
            'Wrote %d issues and %d Gerrit changes (ids from %d).',
            $counts['issue'],
            $counts['review'],
            MockDataGenerator::ID_OFFSET + 1,
        ));
        $io->text('Log in as one of the dev users and open "My Work" to see them.');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{name: string, email: string, account: string}>
     */
    private function people(): array
    {
        $people = [];
        foreach ($this->devUserRegistry->all() as $identifier => $user) {
            $people[] = [
                'name' => $user['name'],
                // The same address the dev login gives the user
                'email' => $user['email'] ?? $identifier . '@forger.ddev.site',
                'account' => (string) $identifier,
            ];
        }

        return [...$people, ...self::EXTERNAL_PEOPLE];
    }

    /**
     * Mock documents are recognized by their id range, real documents are not touched.
     */
    private function remove(): void
    {
        $idRange = ['range' => ['id' => ['gt' => MockDataGenerator::ID_OFFSET, 'lte' => MockDataGenerator::ID_LIMIT]]];
        foreach (['issue', 'review'] as $type) {
            ElasticUtility::getIndexByName($type)->deleteByQuery(['query' => $idRange], ['refresh' => true]);
        }
    }

    /**
     * @param list<array<string, mixed>>             $documents
     * @param callable(array<string, mixed>): string $idOf
     */
    private function write(string $type, array $documents, callable $idOf): int
    {
        $index = ElasticUtility::getIndexByName($type);
        $index->addDocuments(array_map(static fn (array $doc): Document => new Document($idOf($doc), $doc), $documents));
        $index->refresh();

        return count($documents);
    }
}
