<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Elastic\Indices;
use App\Enum\ReviewStatusEnum;
use App\Repository\BackportBlacklistEntryRepository;
use Elastica\Exception\InvalidException;
use Elastica\Result;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(name: 'app:backports', description: 'Creates a list of available backports')]
class BackportCommand extends Command
{
    /**
     * The versions of the footer "Releases:" and their branches in Gerrit, see also App\Elastic\Backports.
     */
    public const array VERSION_BRANCHES = [
        'main' => 'main',
        '6.2' => 'TYPO3_6-2',
        '7.6' => 'TYPO3_7-6',
        '8.7' => 'TYPO3_8-7',
        '9.5' => '9.5',
        '10.4' => '10.4',
        '11.5' => '11.5',
        '12.4' => '12.4',
        '13.4' => '13.4',
        '14.3' => '14.3',
    ];

    public function __construct(
        private readonly Indices $indices,
        private readonly ParameterBagInterface $parameters,
        private readonly BackportBlacklistEntryRepository $blackListRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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
                    't3_main' => 'tbd',
                    't3_62' => 'notTargeted',
                    't3_76' => 'notTargeted',
                    't3_87' => 'notTargeted',
                    't3_95' => 'notTargeted',
                    't3_104' => 'notTargeted',
                    't3_115' => 'notTargeted',
                    't3_124' => 'notTargeted',
                    't3_134' => 'notTargeted',
                ],

            ];
            foreach ($releases as $release) {
                $release = str_replace('master', 'main', (string) $release);
                $workingArray[$changeId]['releases']['t3_' . str_replace('.', '', strtolower($release))] = $this->compareReleaseAndRelatedChanges($release, $relatedChanges);
            }
            // Remove entry completely if all branches have backports
            $hasAllBackports = true;
            if (in_array('missing', $workingArray[$changeId]['releases'], true)) {
                $hasAllBackports = false;
            } else {
                foreach ($workingArray[$changeId]['releases'] as $review) {
                    if (is_array($review) && ReviewStatusEnum::NEW->value === $review['reviewstatus']) {
                        $hasAllBackports = false;
                    }
                }
            }
            if ($hasAllBackports) {
                unset($workingArray[$changeId]);
            }
            /* @noinspection DisconnectedForeachInstructionInspection */
            $progressBar->advance();
        }
        $finalFilePath = $this->parameters->get('kernel.project_dir') . '/var/backports.json';
        file_put_contents($finalFilePath, json_encode($workingArray, JSON_THROW_ON_ERROR));

        $io->success('Wrote backports to ' . $finalFilePath);

        return Command::SUCCESS;
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
                                'reviewstatus' => [ReviewStatusEnum::MERGED->value],
                            ],
                        ],
                        [
                            'terms' => [
                                'branch' => [
                                    'main',
                                ],
                            ],
                        ],
                    ],
                    'should' => [
                        [
                            'terms' => [
                                'releases' => [
                                    '13.4',
                                    '14.3',
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
        $query = $this->indices->get('review')->createSearch($fullRequest);

        return $query->search()->getResults();
    }

    /**
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
        $query = $this->indices->get('review')->createSearch($fullRequest);

        return $query->search()->getResults();
    }

    /**
     * @throws InvalidException
     */
    protected function compareReleaseAndRelatedChanges(string $release, array $relatedChanges): string|array
    {
        $returnValue = 'missing';
        foreach ($relatedChanges as $relatedChange) {
            $relatedChange = $relatedChange->getSource();
            unset($relatedChange['mergeable']);

            $branchForRelease = self::VERSION_BRANCHES[$release] ?? null;
            if ($branchForRelease === $relatedChange['branch']) {
                return $relatedChange;
            }
        }

        return $returnValue;
    }
}
