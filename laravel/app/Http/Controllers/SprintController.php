<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\Yaml\Parser;
use Debugbar;

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
     * @return Response
     */
    public function getIndex()
    {
        $this->prepareBoardData($this->boardId);

        return $this->view->render('index');
    }

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getList()
    {
        $this->prepareBoardData($this->boardId);

        return $this->view->render('list');
    }

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getFocus()
    {
        $this->prepareBoardData($this->boardId, 'FocusBoards');

        return $this->view->render('focus');
    }

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getCig()
    {
        $this->prepareBoardData($this->boardId, 'CIGBoards');

        return $this->view->render('cig');
    }

    /**
     * @param string $boardId
     * @param string $boardType
     */
    protected function prepareBoardData($boardId = '', $boardType = 'Boards')
    {
        $boardInfo = [];
        $this->getAllUsers();
        $this->view->assign('boardMenu', $this->makeBoardMenu($boardId, $boardType));
        if (!isset($this->sprintConfig['T3G']['Forger'][$boardType][$boardId]) && !is_numeric($boardId)) {
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
        foreach ($this->sprintConfig['T3G']['Forger'][$boardType] as $boardId => $boardSetup) {
            $resultCount = $this->getTicketByBoardQuery($boardSetup['Query']);
            $out[] = [
                'id' => $boardId,
                'name' => $boardSetup['Name'],
                'count' => $resultCount,
                'active' => ($boardId === $active) ? true : false,
            ];
        }

        return $out;
    }

    /**
     * @param array $query
     *
     * @return string
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
                $count['closed'] = $count['closed'] + $bucket['doc_count'];
            } elseif ($bucket['key'] === 8) {
                $count['review'] = $count['review'] + $bucket['doc_count'];
            } else {
                $count['open'] = $count['open'] + $bucket['doc_count'];
            }
            $count['total'] = $count['total'] + $bucket['doc_count'];
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
        $out = [
            'BLOCKED' => [],
            'Open' => [],
            'WIP' => [],
            'Review' => [],
            'Done' => [],
        ];
        $fullRequest = [
            'query' => $query,
            'filter' => $this->queryFilters(),
            'size' => 10000,
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('issue');
        $resultSet = $search->search();
        foreach ($resultSet->getResults() as $ticket) {
            $status = $ticket->__get('status');
            $getBoardGroup = $this->defineBoardGroup($status['name']);
            if ($getBoardGroup === 'Done') {
                //@todo yes, this is butt-ugly, but either like this or with two queries.
                $ticketTime = new \DateTime($ticket->__get('updated_on'));
                $now = new \DateTime();
                $ageDiff = $now->diff($ticketTime);
                $age = (int) $ageDiff->format('%a');
                if ($age > 30) {
                    ++$this->closedTicketsNotBeingShown;
                    continue;
                }
            }
            if ($getBoardGroup !== 'Review') {
                $ticketInfo = $ticket->getData();
                if (isset($ticketInfo['assigned_to']) && isset($this->users[$ticketInfo['assigned_to']['id']])) {
                    $ticketInfo['avatar'] = $this->users[$ticketInfo['assigned_to']['id']];
                }
                $out[$getBoardGroup][] = $ticketInfo;
            } else {
                $out[$getBoardGroup][] = $this->getReviewCard($ticket->getData('id'));
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
                        strstr($journalEntry['notes'], 'It is available at http://review.typo3.org/')
                        ||
                        strstr($journalEntry['notes'], 'It is available at https://review.typo3.org/')
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
            if (isset($ticket['assigned_to']) && isset($this->users[$ticket['assigned_to']['id']])) {
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
        switch ($status) {
            case 'Rejected':
                $metaStatus = 'Done';
                break;
            case 'Closed':
                $metaStatus = 'Done';
                break;
            case 'Resolved':
                $metaStatus = 'Done';
                break;

            case 'Under Review':
                $metaStatus = 'Review';
                break;

            case 'In Progress':
                $metaStatus = 'WIP';
                break;

            case 'Needs Feedback':
                $metaStatus = 'BLOCKED';
                break;
            default:
                $metaStatus = 'Open';
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
        $data['Done'] = $data['Done'] + $this->closedTicketsNotBeingShown;
        $fullCount = array_sum($data);
        $percentage = $fullCount / 100;
        $percentage = $percentage ?: 1;
        foreach ($data as $key => $count) {
            $out['sections'][strtolower($key)]['classes'] = strtolower($key);
            if ($key === 'WIP') {
                $out['sections'][strtolower($key)]['classes'] = 'wip active progress-bar-striped';
            }

            $out['sections'][strtolower($key)]['value'] = ($count / $percentage);
            $out['sections'][strtolower($key)]['label'] = $key;
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
        $res = $this->connection->getElasticIndex()->getType('user')->search('', array('size' => 100));
        $results = $res->getResults();
        foreach ($results as $hit) {
            $user = $hit->getSource();
            $this->users[$user['id']] = $user;
        }
        Debugbar::stopMeasure(__FUNCTION__);
    }

    protected function getSprintConfig()
    {
        Debugbar::startMeasure('loadYaml', 'Load YAML Config');
        $yaml = new Parser();
        $config = $yaml->parse(file_get_contents(config_path().'/Sprints.yaml'));
        Debugbar::stopMeasure('loadYaml');

        return $config;
    }
}
