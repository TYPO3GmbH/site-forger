<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use App\Issue;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Parser;
use Debugbar;

/**
 * Class SprintController.
 */
class SprintController extends Controller
{
    /**
     * Board settings.
     *
     * @var array
     */
    protected $sprintConfig;

    /**
     * @var array
     */
    protected $users;

    /**
     * Central BoardId Param from request.
     *
     * @var string
     */
    protected $boardId = '';

    /**
     * The amount of tickets we do not show because they have been updated longer than 30 days ago.
     * We do this because we need to save some Fluid rendering resources.
     *
     * @var int
     */
    protected $closedTicketsNotBeingShown = 0;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * SprintController constructor.
     *
     * @param Connection $connection
     * @param Request $request
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __construct(Connection $connection, Request $request)
    {
        parent::__construct();
        date_default_timezone_set('UTC');
        $this->connection = $connection;
        $this->sprintConfig = $this->getSprintConfig();
        if ($request->has('boardId')) {
            $this->boardId = $request->input('boardId');
        }
    }

    /**
     * Show the index screen to the user.
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getIndex() : string
    {
        $this->prepareBoardData($this->boardId);
        $this->view->assign('title', 'Sprints');
        $this->view->assign('actionName', 'index');

        return $this->view->render('index');
    }

    /**
     * Show the index screen to the user.
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getFocus() : string
    {
        $this->prepareBoardData($this->boardId, 'FocusBoards');
        $this->view->assign('title', 'Focus Sprint');
        $this->view->assign('actionName', 'Focus');

        return $this->view->render('index');
    }

    /**
     * Show the index screen to the user.
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getCig() : string
    {
        $this->prepareBoardData($this->boardId, 'CIGBoards');
        $this->view->assign('title', 'Community Interest Sprint');
        $this->view->assign('actionName', 'cig');

        return $this->view->render('index');
    }

    /**
     * @param string $boardId
     * @param string $boardType
     *
     * @throws \Exception
     */
    protected function prepareBoardData($boardId = '', $boardType = 'Boards')
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
            $query = array(
                'bool' => array(
                    'must' => array(
                        array(
                            'term' => array(
                                'assigned_to.id' => (int) $boardId,
                            ),
                        ),
                    ),
                ),
            );
            $boardInfo['Name'] = '';
            if (isset($this->users[$boardId])) {
                $boardInfo['Name'] = 'Private Board: '.$this->users[$boardId]['fullname'];
            }
        }
        $boardData = $this->getBoardData($query);
        $ticketCount = [];
        foreach ($boardData as $status => $tickets) {
            $ticketCount[$status] = count($tickets);
        }
        $this->view->assignMultiple([
            'progress' => $this->calculateProgressBars($ticketCount),
            'board' => $boardData,
            'boardInfo' => $boardInfo
        ]);
        Debugbar::startMeasure('Fluid', 'Fluid Rendering');
    }

    /**
     * Generates a list of boards to link to.
     *
     * @param string $active
     * @param string $boardType
     *
     * @return array
     * @throws \Elastica\Exception\InvalidException
     */
    protected function makeBoardMenu($active = '', $boardType = 'Boards') : array
    {
        $out = [];
        if (is_array($this->sprintConfig['T3G']['Forger'][$boardType])) {
            foreach ((array)$this->sprintConfig['T3G']['Forger'][$boardType] as $boardId => $boardSetup) {
                $resultCount = $this->getTicketByBoardQuery($boardSetup['Query']);
                $out[] = [
                    'id' => $boardId,
                    'name' => $boardSetup['Name'],
                    'count' => $resultCount,
                    'active' => $boardId === $active,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array $query
     *
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getTicketByBoardQuery(array $query) : array
    {
        $fullRequest = [
            'query' => $query,
            'filter' => $this->queryFilters(),
            'size' => 0,
            'aggregations' => [
                'status' => [
                    'terms' => [
                        'field' => 'status.id',
                    ],
                ],
            ],
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
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
     * @param string $query
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function getBoardData($query) : array
    {
        Debugbar::startMeasure(__FUNCTION__, __FUNCTION__);
        $this->view->assign('boardConfig', json_encode($query, JSON_PRETTY_PRINT));
        $out = $this->sprintConfig['T3G']['Forger']['ColumnOrder'];
        $fullRequest = [
            'query' => $query,
            'filter' => $this->queryFilters(),
            'size' => 10000,
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('issue');
        $resultSet = $search->search();
        foreach ($resultSet->getResults() as $ticket) {
            $status = $ticket->status;
            $getBoardGroup = $this->defineBoardGroup($status['name']);
            if ($getBoardGroup === 'Review' && config('app.app.useGerrit')) {
                $out[$getBoardGroup][] = $this->getReviewCard($ticket->getData());
            } else {
                $ticketInfo = $ticket->getData();
                if (isset($ticketInfo['assigned_to'], $this->users[$ticketInfo['assigned_to']['id']])) {
                    $ticketInfo['avatar'] = $this->users[$ticketInfo['assigned_to']['id']];
                }
                $out[$getBoardGroup][] = $ticketInfo;
            }
        }
        Debugbar::stopMeasure(__FUNCTION__);

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
    protected function getReviewCard(array $ticket) : array
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
            $pattern = '|.*\/\/review.typo3.org\/(?<reviewId>[\d]{1,6})|';
            preg_match($pattern, $notes, $matches);
            if (!isset($matches['reviewId'])) {
                throw new \InvalidArgumentException('There should be a review id in ' . $notes);
            }
            $reviewType = $this->connection->getElasticIndex()->getType('review');
            try {
                $item = $reviewType->getDocument($matches['reviewId']);
            } catch (\Exception $e) {
                return $ticket;
            }
            $returnValue = $item->getData();
            if (isset($ticket['assigned_to'], $this->users[$ticket['assigned_to']['id']])) {
                $returnValue['avatar'] = $this->users[$ticket['assigned_to']['id']];
            }
            $returnValue['_redmine_issue_id'] = $ticket['id'];

            return $returnValue;
        }
        $returnValue['_redmine_issue_id'] = $ticket['id'];

        return $returnValue;
    }

    /**
     * @return array
     */
    protected function queryFilters() : array
    {
        return [
            'bool' => [
                'must' => [],
                'must_not' => [],
            ],
        ];
    }

    /**
     * @param string $status
     *
     * @return string
     */
    protected function defineBoardGroup($status) : string
    {
        return array_key_exists($status, $this->sprintConfig['T3G']['Forger']['StatusMapping'])
            ? $this->sprintConfig['T3G']['Forger']['StatusMapping'][$status]
            : $this->sprintConfig['T3G']['Forger']['StatusMapping']['DefaultState'];
    }

    /**
     * @param array $data
     *
     * @return array
     */
    protected function calculateProgressBars(array $data) : array
    {
        $out = [];
        $data['Done'] += $this->closedTicketsNotBeingShown;
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

    /**
     *
     */
    protected function getAllUsers()
    {
        Debugbar::startMeasure(__FUNCTION__, __FUNCTION__);
        $res = $this->connection->getElasticIndex()->getType('user')->search('', ['size' => 100]);
        $results = $res->getResults();
        foreach ($results as $hit) {
            $user = $hit->getSource();
            $this->users[$user['id']] = $user;
        }
        Debugbar::stopMeasure(__FUNCTION__);
    }

    /**
     * @return mixed
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    protected function getSprintConfig()
    {
        Debugbar::startMeasure('loadYaml', 'Load YAML Config');
        $config = (new Parser())->parse(file_get_contents(config_path() . '/' . config('app.app.sprintConfig')));
        Debugbar::stopMeasure('loadYaml');

        return $config;
    }

    /**
     * @return string
     * @throws \Elastica\Exception\InvalidException
     * @throws \Exception
     */
    public function getJunior() : string
    {
        $boardMenu = [];
        $queries = [];
        $juniors = Issue::groupBy('junior_id')->get();
        foreach ($juniors as $index => $junior) {
            $juniorQuery = $this->getJuniorQuery($junior->junior);
            $boardMenu[$junior->junior->id] = [
                'id' => $junior->junior->id,
                'name' => $junior->junior->name,
                'count' => $this->getTicketByBoardQuery($juniorQuery),
                'active' => $junior->junior->id === (int)$this->boardId,
            ];
            $queries[$junior->junior->id] = $juniorQuery;
        }
        if (is_numeric($this->boardId)) {
            $this->view->assign('boardInfo', ['name' => 'Board for ' . $boardMenu[$this->boardId]['name']]);
            $boardData = $this->getBoardData($queries[$this->boardId]);
            $ticketCount = [];
            foreach ($boardData as $status => $tickets) {
                $ticketCount[$status] = count($tickets);
            }
            $this->view->assign('progress', $this->calculateProgressBars($ticketCount));
            $this->view->assign('board', $boardData);
        }
        $this->view->assign('boardMenu', $boardMenu);
        $this->view->assign('title', 'Junior Board');
        $this->view->assign('actionName', 'junior');

        return $this->view->render('index');
    }

    /**
     * @param $juniorName
     *
     * @return array
     */
    protected function getJuniorQuery($juniorName) : array
    {
        $query = [];
        $issues = Issue::where('junior_id', $juniorName->id)->get();
        foreach ($issues as $index => $issue) {
            $query['bool']['should'][] = [
                'term' => [
                    'id' => $issue->issue_number
                ]
            ];
        }
        return $query;
    }
}
