<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Client\ElasticClient;
use App\Entity\SprintBoard;
use App\Repository\SprintBoardCategoryRepository;
use App\Repository\SprintBoardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Elastica\Exception\InvalidException;
use Exception;
use InvalidArgumentException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Yaml\Yaml;

class SprintController extends AbstractController
{
    /**
     * @var SprintBoardCategoryRepository
     */
    private $sprintBoardCategoryRepository;
    /**
     * @var SprintBoardRepository
     */
    private $sprintBoardRepository;
    /**
     * @var ElasticClient
     */
    private $elasticClient;
    /**
     * @var ParameterBagInterface
     */
    private $parameters;
    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(
        SprintBoardCategoryRepository $sprintBoardCategoryRepository,
        SprintBoardRepository $sprintBoardRepository,
        ElasticClient $elasticClient,
        ParameterBagInterface $parameters,
        RequestStack $requestStack
    ) {
        $this->sprintBoardCategoryRepository = $sprintBoardCategoryRepository;
        $this->sprintBoardRepository = $sprintBoardRepository;
        $this->elasticClient = $elasticClient;
        $this->parameters = $parameters;
        $this->requestStack = $requestStack;
    }

    /**
     * @Route("/sprint/reviews/{reviewType}", defaults={"reviewType"="bugfix"}, name="sprint_reviews")
     * @param string $reviewType
     * @return Response
     * @throws Exception
     */
    public function reviewSprintAction(string $reviewType): Response
    {
        $reviewBoardData = $this->getReviewBoardData($reviewType);
        $foo = '';
        return $this->render('sprint/review.html.twig', [
            'menu' => $this->makeReviewMenu($reviewType),
            'cards' => $reviewBoardData,
        ]);
    }

    /**
     * @Route("/sprint/create", name="sprint_create")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function createSprint(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $sprint = new SprintBoard();
        $form = $this->createForm(\App\Form\SprintBoard::class, $sprint, ['entity_manager' => $this->getDoctrine()->getManager()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category = $this->sprintBoardCategoryRepository->find($request->get('sprint_board')['categoryId']);
            if (null !== $category) {
                $sprint->setCategory($category);
                $entityManager->persist($sprint);
                $entityManager->flush();

                $this->addFlash('success', 'Sprint board created.');

                return $this->redirectToRoute('dashboard_index');
            }
        }

        return $this->render(
            'sprint/form.html.twig',
            [
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @Route("/sprint/edit/{sprintId}", name="sprint_edit")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     * @param int $sprintId
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function editSprint(
        Request $request,
        int $sprintId,
        EntityManagerInterface $entityManager
    ): Response {
        $sprint = $this->sprintBoardRepository->find($sprintId);

        if (null === $sprint) {
            $this->redirectToRoute('sprint_create');
        }

        $form = $this->createForm(\App\Form\SprintBoard::class, $sprint, ['entity_manager' => $this->getDoctrine()->getManager()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category = $this->sprintBoardCategoryRepository->find($request->get('sprint_board')['categoryId']);
            if (null !== $category) {
                $sprint->setCategory($category);
                $entityManager->persist($sprint);
                $entityManager->flush();

                $this->addFlash('success', 'Sprint board edited.');

                return $this->redirectToRoute('dashboard_index');
            }
        }

        return $this->render(
            'sprint/form.html.twig',
            [
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @param string $boardId
     *
     * @return array
     * @throws \Exception
     */
    protected function getReviewBoardData($boardId = ''): array
    {
        if (in_array($boardId, ['bugfix', 'feature', 'task'], true)) {
            $query = $this->getReviewBoardQuery($boardId);
            $out = [
                'missesBoth' => [],
                'missesCodeReview' => [],
                'missesTesting' => [],
                'hasPlusOne' => [],
                'mergeConflict' => [],
                'blocked' => [],
                'unclear' => [],
            ];
            $fullRequest = [
                'query' => $query,
                'size' => 500,
                'sort' => [
                    [
                        'updated_on' => [
                            'order' => 'asc'
                        ]
                    ]
                ]
            ];
            $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
            $search->addType('review');
            $resultSet = $search->search();
            foreach ($resultSet->getResults() as $review) {
                $getBoardGroup = $this->defineReviewBoardGroup($review->getSource());
                $out[$getBoardGroup][] = $review->getSource();
            }

            return $out;
        }
        return [];
    }

    /**
     * @param string $boardId
     *
     * @return array
     */
    protected function getReviewBoardQuery($boardId) : array
    {
        $query = [
            'bool' => [
                'must' => [
                    [
                        'terms' => [
                            'reviewtags' => [
                                strtoupper($boardId)
                            ]
                        ],
                    ],
                    [
                        'query_string' => [
                            'default_field' => 'reviewstatus',
                            'query' => 'NEW',
                        ],
                    ],
                ],
                'must_not' => [
                    [
                        'terms' => [
                            'reviewtags' => [
                                'WIP'
                            ]
                        ],
                    ],
                ],

            ],
        ];

        return $query;
    }

    /**
     * Generates a list of boards to link to.
     *
     * @param string $active
     *
     * @return array
     *
     * @throws InvalidException
     */
    protected function makeReviewMenu($active = '') : array
    {
        $out = [
            [
                'id' => 'bugfix',
                'count' => $this->getReviewsByBoardQuery($this->getReviewBoardQuery('bugfix')),
                'name' => 'Bugfixes',
                'active' => 'bugfix' === $active,
            ],
            [
                'id' => 'task',
                'count' => $this->getReviewsByBoardQuery($this->getReviewBoardQuery('task')),
                'name' => 'Tasks',
                'active' => 'task' === $active,
            ],
            [
                'id' => 'feature',
                'count' => $this->getReviewsByBoardQuery($this->getReviewBoardQuery('feature')),
                'name' => 'Features',
                'active' => 'feature' === $active,
            ],
        ];

        return $out;
    }

    /**
     * @param array $query
     *
     * @return string
     *
     * @throws InvalidException
     */
    protected function getReviewsByBoardQuery(array $query) : string
    {
        $fullRequest = [
            'query' => $query,
            'size' => 0,
        ];
        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $search->addType('review');
        $resultSet = $search->search();

        return $resultSet->getTotalHits();
    }

    /**
     * Defines board columns.
     *
     * @param array $review
     *
     * @return string
     */
    protected function defineReviewBoardGroup(array $review) : string
    {
        switch (true) {
            // Blocked or merge conflict
            case $review['mergeable'] === 'no':
                $metaStatus = 'mergeConflict';
                break;
            case
                $review['positive_reviews'] === 0
                && $review['positive_verifications'] === 0
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0:
                $metaStatus = 'missesBoth';
                break;
            case
                $review['positive_reviews'] > 0
                && $review['positive_verifications'] === 0
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0:
                $metaStatus = 'missesTesting';
                break;
            case
                $review['positive_reviews'] === 0
                && $review['positive_verifications'] > 0
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0:
                $metaStatus = 'missesCodeReview';
                break;
            case
                $review['positive_reviews'] > 0
                && $review['positive_verifications'] > 0
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0:
                $metaStatus = 'hasPlusOne';
                break;
            default:
                $metaStatus = 'blocked';
        }

        return $metaStatus;
    }

    /**
     * @Route("/sprint/{category}", defaults={"category"="index"}, name="sprint")
     * @param string $category
     * @return Response
     * @throws Exception
     */
    public function index(string $category): Response
    {
        $sprintCategory = $this->sprintBoardCategoryRepository->findOneBy([
            'identifier' => $category
        ]);
        // Exit early if no category could be found
        if (!$sprintCategory) {
            return new Response('Sprint board not found', Response::HTTP_NOT_FOUND);
        }
        $sprints = $this->sprintBoardRepository->findBy(
            ['category' => $sprintCategory],
            ['sorting' => 'ASC']
        );
        // Find active Sprint
        $activeSprintId = 0;
        $currentRequest = $this->requestStack->getCurrentRequest();
        if ($currentRequest) {
            $activeSprintId = (int)$currentRequest->get('sprint');
        }
        $cards = [];
        $boardInfo = [];
        $progress = [];
        $currentSprint = '';
        if ($activeSprintId > 0) {
            foreach ($sprints as $sprint) {
                if ($sprint->getId() === $activeSprintId) {
                    $currentSprint = $sprint;
                }
            }
            if ($currentSprint instanceof SprintBoard) {
                $cards = $this->getBoardData($currentSprint);
                $boardInfo = $currentSprint->getName();
                $ticketCount = [];
                foreach ($cards as $status => $tickets) {
                    $ticketCount[$status] = ($tickets ? count($tickets) : 0);
                }
                $progress = $this->calculateProgressBars($ticketCount);
            }
        }
        return $this->render('sprint/index.html.twig', [
            'menu' => $this->createMenu($sprints, $activeSprintId),
            'cards' => $cards,
            'boardInfo' => $boardInfo,
            'progress' => $progress,
            'category' => $category
        ]);
    }

    private function createMenu(array $sprints, int $activeId): array
    {
        $menu = [];
        /** @var SprintBoard $sprint */
        foreach ($sprints as $sprint) {
            $resultCount = $this->getTicketByBoardQuery($sprint->getQuery());
            $menuItem = [
                'id' => $sprint->getId(),
                'title' => $sprint->getName(),
                'active' => $activeId === $sprint->getId(),
                'count' => $resultCount

            ];
            $menu[] = $menuItem;
        }
        return $menu;
    }

    /**
     * @param string $query
     *
     * @return array
     *
     * @throws InvalidException
     */
    private function getTicketByBoardQuery(string $query) : array
    {
        $query = Yaml::parse($query);
        $fullRequest = [
            'query' => $query,
            'size' => 0,
            'aggregations' => [
                'status' => [
                    'terms' => [
                        'field' => 'status.id',
                    ],
                ],
            ],
        ];
        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $search->addType('issue');
        $resultSet = $search->search();
        $count = [
            'open' => 0,
            'closed' => 0,
            'blocked' => 0,
            'review' => 0,
            'total' => 0,
        ];
        foreach ((array)$resultSet->getAggregation('status')['buckets'] as $bucket) {
            switch ($bucket['key']) {
                case 3:
                case 5:
                case 6:
                    $count['closed'] += $bucket['doc_count'];
                    break;
                case 8:
                    $count['review'] += $bucket['doc_count'];
                    break;
                case 4:
                case 7:
                    $count['blocked'] += $bucket['doc_count'];
                    break;
                default:
                    $count['open'] += $bucket['doc_count'];
                    break;
            }
            $count['total'] += $bucket['doc_count'];
        }
        if ($count['total'] > 0) {
            $count['reviewPercentage'] = round($count['review'] * 100.0 / $count['total']);
            if ($count['reviewPercentage'] < 1) {
                unset($count['reviewPercentage']);
            }
            $count['blockedPercentage'] = round($count['blocked'] * 100.0 / $count['total']);
            if ($count['blockedPercentage'] < 1) {
                unset($count['blockedPercentage']);
            }
            $count['percentage'] = round($count['closed'] * 100.0 / $count['total']);
            if ($count['percentage'] < 1 && $count['total'] < 1) {
                unset($count['percentage']);
            }
        }

        return $count;
    }

    /**
     * @param SprintBoard $sprint
     * @return array
     *
     * @throws Exception
     */
    private function getBoardData(SprintBoard $sprint) : array
    {
        $out = $this->parameters->get('ColumnOrder');
        $fullRequest = [
            'query' => Yaml::parse($sprint->getQuery()),
            'size' => 10000,
        ];
        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $search->addType('issue');
        $resultSet = $search->search();
        foreach ($resultSet->getResults() as $ticket) {
            $status = $ticket->getSource()['status'];
            $getBoardGroup = $this->defineBoardGroup($status['name']);
            if ($getBoardGroup === 'Review') {
                $out[$getBoardGroup][] = $this->getReviewCard($ticket->getData());
            } else {
                $ticketInfo = $ticket->getData();
                $out[$getBoardGroup][] = $ticketInfo;
            }
        }
        return $out;
    }

    /**
     * Special handling for review cards.
     *
     * @param array $ticket
     *
     * @return array
     *
     * @throws Exception
     */
    private function getReviewCard(array $ticket) : array
    {
        $notes = '';
        if (isset($ticket['journals'])) {
            foreach ((array)$ticket['journals'] as $journalEntry) {
                if (isset($journalEntry['notes']) && (
                    false !== strpos($journalEntry['notes'], 'It is available at http://review.typo3.org/')
                        ||
                        false !== strpos($journalEntry['notes'], 'It is available at https://review.typo3.org/')
                    )
                ) {
                    $notes = $journalEntry['notes'];
                }
            }
            if ($notes === '') {
                return $ticket;
            }
            $pattern = '|.*https:\/\/review\.typo3\.org\/(?>(c\/)?)(?>Packages\/TYPO3\.CMS\/\+\/)?(?<reviewId>[\d]{2,6})|';
            preg_match($pattern, $notes, $matches);
            if (!isset($matches['reviewId'])) {
                throw new InvalidArgumentException('There should be a review id in ' . $notes);
            }
            $reviewType = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->getType('review');

            try {
                $item = $reviewType->getDocument($matches['reviewId']);
            } catch (Exception $e) {
                return $ticket;
            }
            $returnValue = $item->getData();
            $returnValue['_redmine_issue_id'] = $ticket['id'];

            return $returnValue;
        }
        $returnValue['_redmine_issue_id'] = $ticket['id'];

        return $returnValue;
    }

    /**
     * @param string $status
     *
     * @return string
     */
    private function defineBoardGroup($status) : string
    {
        return array_key_exists($status, $this->parameters->get('StatusMapping'))
            ? $this->parameters->get('StatusMapping')[$status]
            : $this->parameters->get('StatusMapping')['DefaultState'];
    }

    /**
     * @param array $data
     *
     * @return array
     */
    private function calculateProgressBars(array $data) : array
    {
        $out = [];
        $fullCount = array_sum($data);
        $percentage = $fullCount / 100;
        $percentage = $percentage ?: 1;
        foreach ($data as $key => $count) {
            $lowerKey = strtolower($key);
            $out['sections'][$lowerKey]['classes'] = $lowerKey;
            if ($key === 'WIP') {
                $out['sections'][$lowerKey]['classes'] = 'wip active progress-bar-striped';
            }

            $out['sections'][$lowerKey]['value'] = ($count / $percentage);
            $out['sections'][$lowerKey]['label'] = $key;
        }
        $out['total'] = $fullCount;

        return $out;
    }
}
