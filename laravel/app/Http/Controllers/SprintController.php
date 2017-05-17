<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use App\Issue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function getIndex()
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
    public function getFocus()
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
    public function getCig()
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
        $this->getAllUsers();
        $this->view->assign('boardMenu', $this->makeBoardMenu($boardId, $boardType));
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
            if (isset($this->users[$boardId])) {
                $boardInfo['Name'] = 'Private Board: '.$this->users[$boardId]['fullname'];
            } else {
                $boardInfo['Name'] = '';
            }
        }
        $boardData = $this->getBoardData($query);
        $ticketCount = [];
        foreach ($boardData as $status => $tickets) {
            $ticketCount[$status] = count($tickets);
        }
        $this->view->assign('progress', $this->calculateProgressBars($ticketCount));
        $this->view->assign('board', $boardData);
        $this->view->assign('boardInfo', $boardInfo);
        Debugbar::startMeasure('Fluid', 'Fluid Rendering');
    }

    /**
     * Generates a list of boards to link to.
     *
     * @param string $active
     * @param string $boardType
     *
     * @return array
     */
    protected function makeBoardMenu($active = '', $boardType = 'Boards')
    {
        $out = [];
        if (is_array($this->sprintConfig['T3G']['Forger'][$boardType])) {
            foreach ($this->sprintConfig['T3G']['Forger'][$boardType] as $boardId => $boardSetup) {
                $resultCount = $this->getTicketByBoardQuery($boardSetup['Query']);
                $out[] = [
                    'id' => $boardId,
                    'name' => $boardSetup['Name'],
                    'count' => $resultCount,
                    'active' => ($boardId === $active) ? true : false,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array $query
     *
     * @return string
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getTicketByBoardQuery(array $query)
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
            'review' => 0,
            'total' => 0,
        ];
        foreach ($resultSet->getAggregation('status')['buckets'] as $bucket) {
            if ($bucket['key'] === 3 || $bucket['key'] === 5 || $bucket['key'] === 6) {
                $count['closed'] += $bucket['doc_count'];
            } elseif ($bucket['key'] === 8) {
                $count['review'] += $bucket['doc_count'];
            } else {
                $count['open'] += $bucket['doc_count'];
            }
            $count['total'] += $bucket['doc_count'];
        }
        if ($count['total'] > 0) {
            $count['reviewPercentage'] = round($count['review'] * 100.0 / $count['total']);
            if ($count['reviewPercentage'] < 1) {
                unset($count['reviewPercentage']);
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
    protected function getBoardData($query)
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
            if ($getBoardGroup === 'Done') {
                //@todo yes, this is butt-ugly, but either like this or with two queries.
                $ticketTime = new \DateTime($ticket->updated_on);
                $now = new \DateTime();
                $ageDiff = $now->diff($ticketTime);
                $age = (int) $ageDiff->format('%a');
                if ($age > 30) {
                    ++$this->closedTicketsNotBeingShown;
                    continue;
                }
            }
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
    protected function getReviewCard(array $ticket)
    {
        $notes = '';
        if (isset($ticket['journals'])) {
            foreach ($ticket['journals'] as $journalEntry) {
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
            $pattern = '|.*\/\/review.typo3.org\/(?<reviewId>[0-9]{1,6})|';
            preg_match($pattern, $notes, $matches);
            if (!isset($matches['reviewId'])) {
                throw new \Exception('There should be a review id in '.$notes);
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
    protected function queryFilters()
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
    protected function defineBoardGroup($status)
    {
        if (array_key_exists($status, $this->sprintConfig['T3G']['Forger']['StatusMapping'])) {
            $metaStatus = $this->sprintConfig['T3G']['Forger']['StatusMapping'][$status];
        } else {
            $metaStatus = $this->sprintConfig['T3G']['Forger']['StatusMapping']['DefaultState'];
        }
        return $metaStatus;
    }

    /**
     * @param array $data
     *
     * @return array
     */
    protected function calculateProgressBars(array $data)
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
        $yaml = new Parser();
        $config = $yaml->parse(file_get_contents(config_path().'/' . config('app.app.sprintConfig')));
        Debugbar::stopMeasure('loadYaml');

        return $config;
    }

    public function getJunior()
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
                'active' => ($junior->junior->id === (int)$this->boardId) ? true : false,
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

    protected function getJuniorQuery($juniorName)
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
