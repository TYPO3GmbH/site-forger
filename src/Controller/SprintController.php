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
use Elastica\Exception\InvalidException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
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
    )
    {
        $this->sprintBoardCategoryRepository = $sprintBoardCategoryRepository;
        $this->sprintBoardRepository = $sprintBoardRepository;
        $this->elasticClient = $elasticClient;
        $this->parameters = $parameters;
        $this->requestStack = $requestStack;
    }

    /**
     * @Route("/sprint/{category}", defaults={"category"="index"}, name="sprint")
     * @param string $category
     * @return Response
     * @throws \Exception
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
        $sprints = $this->sprintBoardRepository->findBy([
            'category' => $sprintCategory
        ]);
        // Find active Sprint
        $activeSprintId = (int) $this->requestStack->getCurrentRequest()->get('sprint');
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
                    $ticketCount[$status] = ($tickets ? \count($tickets) : 0);
                }
                $progress = $this->calculateProgressBars($ticketCount);
            }
        }
        return $this->render('sprint/index.html.twig', [
            'controller_name' => 'SprintController',
            'menu' => $this->createMenu($sprints, $activeSprintId),
            'cards' => $cards,
            'boardInfo' => $boardInfo,
            'progress' => $progress
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
                'active' => $activeId === $sprint->getId(), //@todo fix this part
                'count' => $resultCount

            ];
            $menu[] = $menuItem;
        }
        return $menu;
    }

    /**
     * @param string $boardId
     * @param string $boardType
     *
     * @throws \Exception
     */
    private function prepareBoardData($boardId = '', $boardType = 'Boards')
    {
        $boardInfo = [];
        $this->view->assign('boardMenu', $this->makeBoardMenu($boardId, $boardType));
        $this->getAllUsers();
        if (!is_numeric($boardId) && !isset($this->sprintConfig['T3G']['Forger'][$boardType][$boardId])) {
            return;
        }
        $query = '';
        if (isset($this->sprintConfig['T3G']['Forger'][$boardType][$boardId])) {
            $query = $this->sprintConfig['T3G']['Forger'][$boardType][$boardId]['Query'];
            $boardInfo = $this->sprintConfig['T3G']['Forger'][$boardType][$boardId];
        }
        if (is_numeric($boardId)) {
            $query = [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'assigned_to.id' => (int) $boardId,
                            ],
                        ],
                    ],
                ],
            ];
            $boardInfo['Name'] = '';
            if (isset($this->users[$boardId])) {
                $boardInfo['Name'] = 'Private Board: ' . $this->users[$boardId]['fullname'];
            }
        }
        $boardData = $this->getBoardData($query);
        $ticketCount = [];
        foreach ($boardData as $status => $tickets) {
            $ticketCount[$status] = ($tickets ? \count($tickets) : 0);
        }
        $this->view->assignMultiple([
            'progress' => $this->calculateProgressBars($ticketCount),
            'board' => $boardData,
            'boardInfo' => $boardInfo
        ]);
        Debugbar::startMeasure('Fluid', 'Fluid Rendering');
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
     * @throws \Exception
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
            $status = $ticket->status;
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
     * @throws \Exception
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
                throw new \InvalidArgumentException('There should be a review id in ' . $notes);
            }
            $reviewType = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->getType('review');

            try {
                $item = $reviewType->getDocument($matches['reviewId']);
            } catch (\Exception $e) {
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
//        $data['Done'] += $this->closedTicketsNotBeingShown;
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
