<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Client\ElasticClient;
use App\Repository\EmploymentTimeRepository;
use DateTime;
use Elastica\Document;
use Elastica\Result;
use Elastica\Scroll;
use Elastica\Type as DocType;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CalculateKarmaCommand extends Command
{
    protected static $defaultName = 'app:calculate-karma';
    /**
     * @var ParameterBagInterface
     */
    private $parameters;
    /**
     * @var ElasticClient
     */
    private $elasticClient;

    private $companyMapping = [];

    private $unmappedAccounts = [];
    /**
     * @var EmploymentTimeRepository
     */
    private $employmentTimesRepository;

    public function __construct(ParameterBagInterface $parameters, ElasticClient $elasticClient, EmploymentTimeRepository $employmentTimeRepository)
    {
        parent::__construct();
        $this->parameters = $parameters;
        $this->elasticClient = $elasticClient;
        $this->employmentTimesRepository = $employmentTimeRepository;
        $this->setUpCompanyMapping();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Calculate Karma point based on the data from forger')
            ->addArgument('year', InputArgument::OPTIONAL, 'the year to import')
            ->addOption('account', 'a', InputOption::VALUE_OPTIONAL, 'which account to update')

        ;
    }

    protected function setUpCompanyMapping(): void
    {
        $employmentTimes = $this->employmentTimesRepository->findAll();
        foreach ($employmentTimes as $employmentTime) {
            if ($employmentTime->getEmployedTo() === null) {
                $employmentTime->setEmployedTo(new DateTime('now'));
            }
            $account = $employmentTime->getAccount();
            try {
                $company = $employmentTime->getCompany();
            } catch (Exception $e) {
                $company = 'broken-relation';
            }
            $name = $company->getName();
            $this->companyMapping[$account][$name] = [
                'from' => $employmentTime->getEmployedFrom(),
                'to' => $employmentTime->getEmployedTo()
            ];
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $year = (int)$input->getArgument('year');
        $yearTo = $year+1;
        $finalConstraints = [];
        $constraints['dateRange'] = [
            'range' => [
                'interacted_on' => [
                    'gte' => $year . '/01/01 00:00:00',
                    'lte' => $yearTo . '/01/01 00:00:00'
                ]
            ]
        ];
        if ($year === 0) {
            $constraints['dateRange'] = [
                'range' => [
                    'interacted_on' => [
                        'gte' => 'now-3d',
                    ]
                ]
            ];
        }

        $accountName = $input->getOption('account');
        if ($accountName !== null) {
            $constraints['account'] = [
                'terms' => [
                    'account' => [$accountName]
                ]
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
                                    'Mr. Jenkins'
                                ]
                            ]
                        ]
                    ]
                ],
            ],
            'size' => 1000
        ];
        $numberOfInteractions = $this->getInteractionCount($fullRequest);
        $mainProgressBar = new ProgressBar($output, $numberOfInteractions);
        $mainProgressBar->setFormat('[%bar%] %current%/%max%  %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $search->addType('interactions');

        $scroll = new Scroll($search, '4h');
        foreach ($scroll as $scrollId => $resultSet) {
            foreach ($resultSet->getResults() as $interaction) {
                $this->setPointsForInteraction($interaction);
                /** @noinspection DisconnectedForeachInstructionInspection */
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
                    $unmappedAccount[1]
                ]);
                $counter++;
            }
            $table->render();
        }
        $io->success('Done importing karma points');
    }

    private function getInteractionCount(array $fullRequest): int
    {
        $fullRequest['size'] = 0;
        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $search->addType('interactions');
        $resultSet = $search->search();
        return $resultSet->getTotalHits();
    }

    private function setPointsForInteraction(Result $interaction): void
    {
        $points = $this->parameters->get('karmaPoints');
        $dataArray = $interaction->getSource();
        $document = [
            'leaderboard_entry_id' => 'forger:review:' . $interaction->getId(),
            'company' => $this->mapAccountToCompany($dataArray),
            'account' => $dataArray['account'],
            'fullname' => $dataArray['fullname'],
            'email' => $dataArray['email'],
            'interacted_on' => $dataArray['interacted_on'],
            'interaction' => $dataArray['interaction_type'],
            'points' => $points[$dataArray['interaction_type']]
        ];
        $elasticType = new DocType($this->elasticClient->getIndex($this->parameters->get('elastic_index_name')), 'karmapoints');
        if ($elasticType !== null) {
            $doc = new Document($document['leaderboard_entry_id'], $document);
            $elasticType->addDocument($doc);
        }
    }

    private function mapAccountToCompany(array $interaction): string
    {
        // Check if user is in array at all
        if (!array_key_exists($interaction['account'], $this->companyMapping)) {
            $this->unmappedAccounts[$interaction['account']] = [
                $interaction['account'],
                $interaction['fullname']
            ];
            return 'unmapped';
        }
        try {
            $interactionTime = new DateTime($interaction['interacted_on']);
        } catch (Exception $e) {
            throw new RuntimeException('Interaction does not appear to be a parsable date');
        }
        foreach ($this->companyMapping[$interaction['account']] as $company => $timeFrames) {
            if ($interactionTime < $timeFrames['to'] && $interactionTime > $timeFrames['from']) {
                return $company;
            }
        }
        return 'unmapped';
    }
}
