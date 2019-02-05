<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Console\Commands;

use App\Elastic\Connection;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Parser;

class Backports extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'Forger:Backports';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds Backports';

    /**
     * @var \Illuminate\Config\Repository
     */
    protected $config;

    /**
     * @var \Elastica\Client
     */
    protected $elasticClient;

    /**
     * @var \Elastica\Index;
     */
    protected $elasticIndex;

    /**
     * @var Connection
     */
    protected $elasticConnection;

    /**
     * ForgeFullImport constructor.
     *
     * @param Repository        $config
     * @param Connection        $elasticConnection
     */
    public function __construct(Repository $config, Connection $elasticConnection)
    {
        parent::__construct();
        $this->config = $config;
        $this->elasticConnection = $elasticConnection;
    }

    /**
     * Initializes all client connections to forge and to Elasticsearch.
     */
    protected function startUp()
    {
        $this->elasticClient = $this->elasticConnection->getElasticClient();
        $this->elasticIndex = $this->elasticConnection->getElasticIndex();
    }

    /**
     *
     * @throws \Elastica\Exception\InvalidException
     */
    public function handle()
    {
        $blockStart = time();
        $this->startUp();
        $workingArray = [];
        $backports = $this->getPotentialBackports();
        $this->output->writeln('Working with ' . \count($backports) . ' potential backports');
        foreach ($backports as $backport) {
            $releases = $backport->getSource()['releases'];
            $changeId = $backport->getSource()['change_id'];
            $relatedChanges = $this->getReviewsByChangeId($changeId);
            $workingArray[$changeId] = [
                'mainReviewId' => $backport->getSource()['id'],
                'releases' => [
                    'master' => 'tbd',
                    '6-2' => 'notTargeted',
                    '7-6' => 'notTargeted',
                    '8-7' => 'notTargeted',
                    '9-5' => 'notTargeted',
                ]

            ];
            foreach ($releases as $release) {
                $workingArray[$changeId]['releases'][str_replace('.', '-', strtolower($release))] = $this->compareReleaseAndRelatedChanges($release, $relatedChanges);
            }
            // Remove entry completely if all branches have backports
            $hasAllBackports = true;
            if (\in_array('missing', $workingArray[$changeId]['releases'], true)) {
                $hasAllBackports = false;
            } else {
                foreach ($workingArray[$changeId]['releases'] as $review) {
                    if (\is_array($review) && $review['reviewstatus'] === 'NEW') {
                        $hasAllBackports = false;
                    }
                }
            }
            if ($hasAllBackports) {
                unset($workingArray[$changeId]);
            }
        }
        $storagePath = storage_path();
        file_put_contents($storagePath . '/app/Cache/backports.json', json_encode($workingArray));
        $this->output->writeln('Finished in ' .
            gmdate('H:i:s', time() - $blockStart) . '.');
    }

    /**
     *
     * @param int $changeId
     * @return array
     * @throws \RuntimeException
     * @throws \Elastica\Exception\InvalidException
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
                                    'Merge',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $query = $this->elasticIndex->createSearch($fullRequest);
        $query->addType('interactions');
        $resultSet = $query->search();
        $results = $resultSet->getResults();
        if (\count($results) > 1 && $results[0]->getData()['interaction_source_id'] !== 57284) {
            throw new \RuntimeException('More than one merger found?');
        }
        if (\count($results) > 0) {
            return $results[0]->getSource();
        }
        return [];
    }

    /**
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getPotentialBackports(): array
    {
        $config = $this->getConfig();
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
                                    '8.7',
                                    '9.5'
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewtags' => [
                                    'WIP',
                                    'SECURITY'
                                ],
                            ],
                        ],
                        [
                            'terms' => [
                                'change_id' => array_keys($config['BackportBlacklist'])
                            ]
                        ],
                        [
                            'range' => [
                                'updated_on' => ['lte' =>  'now-1y']
                            ]
                        ]
                    ],
                    'minimum_should_match' => 1
                ],
            ],
            'sort' => [
                'updated_on'
            ],
            'size' => 5000,
        ];
        $query = $this->elasticIndex->createSearch($fullRequest);
        $query->addType('review');
        $resultSet = $query->search();
        return $resultSet->getResults();
    }

    /**
     *
     * @param string $changeId
     * @return \Elastica\Result[]
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
        $query = $this->elasticIndex->createSearch($fullRequest);
        $query->addType('review');
        $resultSet = $query->search();
        return $resultSet->getResults();
    }

    /**
     * @param string $release
     * @param array $relatedChanges
     * @return mixed|string
     * @throws \Elastica\Exception\InvalidException
     */
    protected function compareReleaseAndRelatedChanges(string $release, array $relatedChanges)
    {
        $returnValue = 'missing';
        foreach ($relatedChanges as $relatedChange) {
            $relatedChange = $relatedChange->getSource();
            $mergingPerson = $this->getPersonWhoMergedTheChange($relatedChange['id']);
            if (isset($mergingPerson['fullname'])) {
                $relatedChange['owner'] = $mergingPerson['fullname'];
            } else {
                $relatedChange['owner'] = '';
            }
            unset($relatedChange['mergeable']);
            switch ($release) {
                case '6.2':
                    if ($relatedChange['branch'] === 'TYPO3_6-2') {
                        return $relatedChange;
                    }
                    break;
                case '7.6':
                    if ($relatedChange['branch'] === 'TYPO3_7-6') {
                        return $relatedChange;
                    }
                    break;
                case '8.7':
                    if ($relatedChange['branch'] === 'TYPO3_8-7') {
                        return $relatedChange;
                    }
                    break;
                case '9.5':
                    if ($relatedChange['branch'] === '9.5') {
                        return $relatedChange;
                    }
                    break;
                case 'master':
                    if ($relatedChange['branch'] === 'master') {
                        return $relatedChange;
                    }
                    break;
                default:
            }
        }
        return $returnValue;
    }

    /**
     * @return mixed
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    protected function getConfig()
    {
        $config = (new Parser())->parse(file_get_contents(config_path() . '/BackportBlacklist.yaml'));
        return $config;
    }
}
