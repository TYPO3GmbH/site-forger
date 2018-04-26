<?php
namespace App\Console\Commands;

use App\Elastic\Connection;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;

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
    public function fire()
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
        if (\count($results) > 1) {
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
    protected function getPotentialBackports()
    {
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
                                    '7.6',
                                    '8.7'
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
                                'change_id' => [
                                    'Iefff83ae483e4a760a9c7b973e40df50270407d5', // too old
                                    'I0c585c0c50674aabf40aeec0af176bb34ad0edfd', // too old
                                    'Iad438ad7e20ab8d818286053557ade26542d8216', // too old
                                    'Iedb71737ab3b69f2873292ea1cab165e0ec21cec', // in-sprint-cherrypick
                                    'I3a2102e2693fc705cad21a67d7292322e6e8b487', // manually cherrypicked
                                    'I4f02d38ba0b8eb8723aa1153a32edc7093e3336e', // manual error in cmt msg
                                    'I9f2f2a455875e509c16cad17e29e629e8b21fe6d', //
                                    'I20fe7367433fe98a118890cc95306ee5e45504f4', // no backport, no security issue
                                    'I06b14a7f6166ec1e9bca412b3b3404762fc7d7b6', // not necessary in 7
                                    'I31af8e8e7f095d3af01740165900474d7e05a9a5', // backported with different change id,
                                    'Ife7e4536fba82dcdd2a5eb8c1ae9a4fecc0d3fae', // got lost, backport was forgotten
                                    'Ic0791d71b7ff5aa3e9caf098ea75fa254efa6c4b', // 7.6 backport is directly integrated into the original patch for 7.6.
                                    'Ia1171867acd150a1fdd595bcea1bda58c98fac36', // no backport to 7.6, the problem can not occurs in 7.6 because the array could never be empty.
                                    'Ib852127208e6fd9ee44888a96265b4b5dfbfa83a', // backport was forgotten?
                                    'I0fb2b4632bef05912a329553b70f26d614a1ba9c', // merged to 7.6 with different changeId
                                    'Idd9242aa1e2ecac3deb542290627fdf9c5479edc', // merged to 7.6 with different changeId
                                    'Ie034d6b192675fa1a453622b90296d39c80d2447', // No backport to 7.6 possible here, the documentation is not there in 7.6.
                                    'If8298c220ca7171d34e21aff9e49917ac8618686', // Not backported whatsoever
                                    'Ia44c039083d44eda463ee1819f6dc20353ce7f8a', // Backport to 7.6 is not possible as the required code was introduced to v8 as a breaking feature, see https://review.typo3.org/49235
                                    'I6cb209b5ebae04b7e29b55a52ac61ce0f4b261c2', // Revert
                                    'Ib6d4599e0f5682e8b72971d0f35ef00cfa8d3e6a', // This patch does not require a backport, since the 7.6 backport of the original patch was not merged yet.
                                    'I8774adb07c50aed24b3d3e94e0a0b913aedeb5a9', // that is not valid for 7.6 as the description is not displayed on top of record
                                    'Id782bd41bfe30d9f9574e4b444d6e361ac76d0c6', // unclear
                                    'Iaea325a679ac8bae116b33b912e3e9e2f7cd9163', // Revert
                                    'If6d71cf966408fcf976365fc01e6a2529bf1902d', // too old
                                    'Ibc6efbf9a2a2360d84aa0ed4c3b74ff731ef1398', // too old
                                    'Iaa3697b04c123bba61a426fdf39ee3959f68b705', // too old
                                    'I177a52c17d5f40ce0a25418721d47ea9daa21881', // Revert
                                    'I43d0c5add037679ee7c99b6cf16efcd2079353fc', // Seems that valuePicker is not available in v7.6, so no backport here
                                    'Icd1e2c2cff3f37ce6763bc4f64d4011443226a68', // let's skip the 7.6 here - the issue is not *that* critical imho and it's quite some work to prepare the backport in this case.
                                    'I94aab84fce72e8b852783820b34065ab1e051ea5', // I'd skip the backport and only patch 7.6 if anyone really needs it.
                                    'Ida24b5adf747dc7c259a966fcddfdb7feb9f0951', // followup
                                    'I321c7bba03ee597adc29a2f8429b8fc9ac3b709d', // Cherrypicked with different change id
                                    'I5a9267b1d4ab53a6a39efdbd411e610dc9233ead', // Cherrypicked with different change id
                                    'I756cb65eba3a58e9edf0995aa9f1f2d4f96f7926', // Cherrypicked with different change id
                                    'I2633df64020e9f6a8e95048ed2d23f84d3013313', // Cherrypicked with different change id
                                    'Id77fc0acceca7af67960b67fd8b8700c98df888e', // Cherrypicked with different change id
                                    'I7408e8580d84e235a1f91399684a80025a62e0e8', // Cherrypicked with different change id
                                    'I519641eadd3342369b66252da2d50ca54726aacb', // Using correct releases helps... (7,6)
                                    'I2077e9e0b0119e6b064b0a6d4ebb9c3dc1d848a4', // Meh, patch is not valid for 7.6, the </td> is correct there.
                                    'I1ec0f2369ae714f2e8d0190a5bf412151405687f', // The whole class was removed again in https://review.typo3.org/#/c/53014/ when reverting https://review.typo3.org/#/c/50803/ - so no backport to 8.7 here.
                                    'I3e04559c05381c49ab390dc3ad0d3eebddc5a57f', // backport won't be done
                                    'I18b1964b860e51256a6d0a5f39fd4c40544bac85', // backport not needed as code different in 8-7
                                    'Ibae4a276ea814f0ce3d453cffef1d22afeff1eb9', // Backport seems too hard. Will be skipped.
                                    'I43586802a9838faa2723fdcaa2e9e15492003830', // Revert
                                    'I67680820b0e139fea49cfcc0818cd97af3f6b9b9', // Revert
                                    'I9cdfbcd5e0f25b832ea546f1b8c70a9f544b31aa', // No backport to 8.7 needed. All is correct there. Wrong commit was https://review.typo3.org/#/c/51151/
                                    'I422f920187186fe83b6043f806fc1be9a16c8a25', // Skip the backport. This was a mistake of mine!
                                    'Ibd8eb7c4023d1b9f0e0de8b75d66193d66b89147', // Revert
                                    'I32f7c8e257f65da4a2fce1d7ee515d7954564387', // Due to the fact that we do not have the cached reflection data available in 8.7 like we do in master
                                    'I3a617b31f197b19231a858f88f992904c681124d', // "Backport" was in this case already done via https://review.typo3.org/#/c/54459/
                                    'Ie71420e5185e42b850ac3bb11d8eb4edd26bb4ad', // Cherrypicked with different change id
                                    'I503623c607554a60bd144c83e3f69e78fe5a7ea0', // For the record: a backport to 7.6 is not necessary as the base path resolving is safe already.
                                    'If7e3893baa73c1392f3317d5b871be51eeab41b5', // Oh, afair our decision at the codesprint was to not backport the new settings to 8.7 as that in turn would cause a lot of cascading changes for the changed typescript
                                    'I9989d17890c381a12f17a0806b46ce269ab1ca5d', // As the code completely changed, I'd prefer not to backport this one to 7.6
                                    'I3b86758495f9481a21691feaddd6000d484e2a5e', // No backport
                                    'Ie9b92b5ee656f5d5bfe1e5bdac52afa6c61eff03', // no backport needed, it is fine in 8.7
                                    'I54e60cd3fbd2ab36e945cd2e2ed9c6fc02a5bf5a', // no backport needed, it is fine in 8.7
                                    'Iad9af42a5ba88324da1f23fc354847bc96d9331d', // no backport needed - this was removed in 9.0 but already built the same way in v8!
                                    'I46ba22bf4141c37db6530912b9e34252d1fb462c', // No backport
                                ]
                            ]
                        ],
                        [
                            'range' => [
                                'updated_on' => ['lte' =>  'now-1000d']
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
            switch($release) {
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
                case '6.2':
                    if ($relatedChange['branch'] === 'TYPO3_6-2') {
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
}
