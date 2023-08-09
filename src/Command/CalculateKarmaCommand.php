<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Repository\EmploymentTimeRepository;
use Elastica\Document;
use Elastica\Result;
use Elastica\Scroll;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use T3G\ElasticUtility\ElasticUtility;

class CalculateKarmaCommand extends Command
{
    protected static $defaultName = 'app:calculate-karma';
    protected static $defaultDescription = 'Calculate Karma point based on the data from forger';
    private array $companyMapping = [];
    private array $unmappedAccounts = [];

    public function __construct(
        private readonly ParameterBagInterface $parameters,
        private readonly EmploymentTimeRepository $employmentTimesRepository
    ) {
        parent::__construct();
        $this->setUpCompanyMapping();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('year', InputArgument::OPTIONAL, 'the year to import')
            ->addOption('account', 'a', InputOption::VALUE_OPTIONAL, 'which account to update')

        ;
    }

    protected function setUpCompanyMapping(): void
    {
        $employmentTimes = $this->employmentTimesRepository->findAll();
        foreach ($employmentTimes as $employmentTime) {
            if (null === $employmentTime->getEmployedTo()) {
                $employmentTime->setEmployedTo(new \DateTime('now'));
            }
            $account = $employmentTime->getAccount();
            try {
                $company = $employmentTime->getCompany();
            } catch (Exception) {
                $company = 'broken-relation';
            }
            $name = $company->getName();
            $this->companyMapping[$account][$name] = [
                'from' => $employmentTime->getEmployedFrom(),
                'to' => $employmentTime->getEmployedTo(),
            ];
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $constraints = [];
        $io = new SymfonyStyle($input, $output);
        $year = (int) $input->getArgument('year');
        $yearTo = $year + 1;
        $finalConstraints = [];
        $constraints['dateRange'] = [
            'range' => [
                'interacted_on' => [
                    'gte' => $year . '/01/01 00:00:00',
                    'lte' => $yearTo . '/01/01 00:00:00',
                ],
            ],
        ];
        if (0 === $year) {
            $constraints['dateRange'] = [
                'range' => [
                    'interacted_on' => [
                        'gte' => 'now-3d',
                    ],
                ],
            ];
        }

        $accountName = $input->getOption('account');
        if (null !== $accountName) {
            $constraints['account'] = [
                'terms' => [
                    'account' => [$accountName],
                ],
            ];
        }
        foreach ($constraints as $constraint) {
            $finalConstraints[] = $constraint;
        }

        // Prepare cli layout
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => $finalConstraints,
                    'must_not' => [
                        [
                            'terms' => [
                                'fullname' => [
                                    'TYPO3com',
                                    'Mr. Jenkins',
                                    'core-ci',
                                    'Replication from review.typo3.org and git.typo3.org',
                                    'github-actions[bot]',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'size' => 1000,
        ];
        $numberOfInteractions = $this->getInteractionCount($fullRequest);
        $mainProgressBar = new ProgressBar($output, $numberOfInteractions);
        $mainProgressBar->setFormat('[%bar%] %current%/%max%  %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $search = ElasticUtility::getIndexByName('interactions')->createSearch($fullRequest);

        $scroll = new Scroll($search, '4h');
        foreach ($scroll as $scrollId => $resultSet) {
            foreach ($resultSet->getResults() as $interaction) {
                $this->setPointsForInteraction($interaction);
                /* @noinspection DisconnectedForeachInstructionInspection */
                $mainProgressBar->advance();
            }
        }
        if (count($this->unmappedAccounts) > 0) {
            $output->writeln('');
            $table = new Table($output);
            $table->setHeaderTitle('Unmapped Contributors');
            $counter = 1;
            foreach ($this->unmappedAccounts as $unmappedAccount) {
                $table->addRow([
                    $counter,
                    $unmappedAccount[0],
                    $unmappedAccount[1],
                ]);
                ++$counter;
            }
            $table->render();
        }
        $io->success('Done importing karma points');

        return Command::SUCCESS;
    }

    private function getInteractionCount(array $fullRequest): int
    {
        $fullRequest['size'] = 0;
        $search = ElasticUtility::getIndexByName('interactions')->createSearch($fullRequest);

        return $search->search()->getTotalHits();
    }

    private function setPointsForInteraction(Result $interaction): void
    {
        $points = $this->parameters->get('karmaPoints');
        $dataArray = $interaction->getSource();
        if (!isset($points[$dataArray['interaction_type']])) {
            return;
        }
        $document = [
            'leaderboard_entry_id' => 'forger:review:' . $interaction->getId(),
            'company' => $this->mapAccountToCompany($dataArray),
            'account' => $dataArray['account'],
            'fullname' => $dataArray['fullname'],
            'email' => $dataArray['email'],
            'interacted_on' => $dataArray['interacted_on'],
            'interaction' => $dataArray['interaction_type'],
            'points' => $points[$dataArray['interaction_type']],
        ];
        $elasticType = ElasticUtility::getIndexByName('karmapoints');
        $doc = new Document($document['leaderboard_entry_id'], $document);
        $elasticType->addDocument($doc);
    }

    private function mapAccountToCompany(array $interaction): string
    {
        // Check if user is in array at all
        if (!array_key_exists($interaction['account'], $this->companyMapping)) {
            $this->unmappedAccounts[$interaction['account']] = [
                $interaction['account'],
                $interaction['fullname'],
            ];

            return 'unmapped';
        }
        try {
            $interactionTime = new \DateTime($interaction['interacted_on']);
        } catch (Exception) {
            throw new \RuntimeException('Interaction does not appear to be a parsable date');
        }
        foreach ($this->companyMapping[$interaction['account']] as $company => $timeFrames) {
            if ($interactionTime < $timeFrames['to'] && $interactionTime > $timeFrames['from']) {
                return $company;
            }
        }

        return 'unmapped';
    }
}
