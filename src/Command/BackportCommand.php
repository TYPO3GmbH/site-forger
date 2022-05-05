<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Repository\BackportBlacklistEntryRepository;
use Elastica\Exception\InvalidException;
use Elastica\Result;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use T3G\ElasticUtility\ElasticUtility;

class BackportCommand extends Command
{
    protected static $defaultName = 'app:backports';
    /**
     * @var array<string, string>
     */
    private static array $versionBranchMap = [
        'master' => 'master',
        '6.2' => 'TYPO3_6-2',
        '7.6' => 'TYPO3_7-6',
        '8.7' => 'TYPO3_8-7',
        '9.5' => '9.5',
        '10.4' => '10.4',
        '11.5' => '11.5',
    ];

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var ParameterBagInterface
     */
    protected $parameters;
    /**
     * @var BackportBlacklistEntryRepository
     */
    private $blackListRepository;

    public function __construct(ParameterBagInterface $parameters, BackportBlacklistEntryRepository $blacklistEntryRepository)
    {
        parent::__construct();
        $this->parameters = $parameters;
        $this->blackListRepository = $blacklistEntryRepository;
    }

    protected function configure(): void
    {
        $this->setDescription('Creates a list of available backports');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $workingArray = [];
        $backports = $this->getPotentialBackports();
        $progressBar = new ProgressBar($output, count($backports));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        foreach ($backports as $backport) {
            $releases = $backport->getSource()['releases'];
            $changeId = $backport->getSource()['change_id'];
            $relatedChanges = $this->getReviewsByChangeId($changeId);
            $workingArray[$changeId] = [
                'mainReviewId' => $backport->getSource()['id'],
                'releases' => [
                    't3_master' => 'tbd',
                    't3_62' => 'notTargeted',
                    't3_76' => 'notTargeted',
                    't3_87' => 'notTargeted',
                    't3_95' => 'notTargeted',
                    't3_104' => 'notTargeted',
                    't3_115' => 'notTargeted',
                ],

            ];
            foreach ($releases as $release) {
                $workingArray[$changeId]['releases']['t3_' . str_replace('.', '', strtolower($release))] = $this->compareReleaseAndRelatedChanges($release, $relatedChanges);
            }
            // Remove entry completely if all branches have backports
            $hasAllBackports = true;
            if (in_array('missing', $workingArray[$changeId]['releases'], true)) {
                $hasAllBackports = false;
            } else {
                foreach ($workingArray[$changeId]['releases'] as $review) {
                    if (is_array($review) && $review['reviewstatus'] === 'NEW') {
                        $hasAllBackports = false;
                    }
                }
            }
            if ($hasAllBackports) {
                unset($workingArray[$changeId]);
            }
            /** @noinspection DisconnectedForeachInstructionInspection */
            $progressBar->advance();
        }
        $finalFilePath = $this->parameters->get('kernel.project_dir') . '/var/backports.json';
        file_put_contents($finalFilePath, json_encode($workingArray));

        $io->success('Wrote backports to ' . $finalFilePath);
    }

    /**
     * @param int $changeId
     * @return array
     * @throws RuntimeException
     * @throws InvalidException
     */
    protected function getPersonWhoMergedTheChange(int $changeId): array
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'terms' => [
                                'interaction_source_id' => [
                                    $changeId,
                                ],
                            ],
                        ],
                        [
                            'terms' => [
                                'interaction_type' => [
                                    'core:review:merge',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $query = ElasticUtility::getIndexByName('interactions')->createSearch($fullRequest);
        $resultSet = $query->search();
        $results = $resultSet->getResults();
        if (count($results) > 1 && $results[0]->getData()['interaction_source_id'] !== 57284) {
            throw new RuntimeException('More than one merger found?');
        }
        if (count($results) > 0) {
            return $results[0]->getSource();
        }
        return [];
    }

    /**
     * @throws InvalidException
     */
    protected function getPotentialBackports(): array
    {
        $blackListedReviews = [];
        $blackList = $this->blackListRepository->findAll();
        foreach ($blackList as $item) {
            $blackListedReviews[] = $item->getChangeId();
        }
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                ],
                            ],
                        ],
                        [
                            'terms' => [
                                'branch' => [
                                    'master',
                                ],
                            ],
                        ],
                    ],
                    'should' => [
                        [
                            'terms' => [
                                'releases' => [
                                    '10.4',
                                    '11.5',
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewtags' => [
                                    'SECURITY',
                                ],
                            ],
                        ],
                        [
                            'term' => [
                                'work_in_progress' => true,
                            ],
                        ],
                        [
                            'terms' => [
                                'change_id' => $blackListedReviews,
                            ],
                        ],
                        [
                            'range' => [
                                'updated_on' => ['lte' => 'now-1y'],
                            ],
                        ],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
            'sort' => [
                'updated_on',
            ],
            'size' => 5000,
        ];
        $query = ElasticUtility::getIndexByName('review')->createSearch($fullRequest);
        $resultSet = $query->search();
        return $resultSet->getResults();
    }

    /**
     * @param string $changeId
     * @return Result[]
     */
    protected function getReviewsByChangeId(string $changeId): array
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'terms' => [
                                'change_id' => [
                                    $changeId,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'size' => 50,
        ];
        $query = ElasticUtility::getIndexByName('review')->createSearch($fullRequest);
        $resultSet = $query->search();
        return $resultSet->getResults();
    }

    /**
     * @param string $release
     * @param array $relatedChanges
     * @return mixed|string
     * @throws InvalidException
     */
    protected function compareReleaseAndRelatedChanges(string $release, array $relatedChanges)
    {
        $returnValue = 'missing';
        foreach ($relatedChanges as $relatedChange) {
            $relatedChange = $relatedChange->getSource();
            if ($relatedChange['reviewstatus'] === 'MERGED') {
                $mergingPerson = $this->getPersonWhoMergedTheChange($relatedChange['id']);
                $relatedChange['owner'] = $mergingPerson['fullname'] ?? '';
            }
            unset($relatedChange['mergeable']);

            $branchForRelease = self::$versionBranchMap[$release] ?? null;
            if ($branchForRelease === $relatedChange['branch']) {
                return $relatedChange;
            }
        }
        return $returnValue;
    }
}
