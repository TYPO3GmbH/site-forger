<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    /**
     * Show the index screen to the user.
     *
     * @param Request $request
     *
     * @return \HttpResponse
     *
     * @throws \Exception
     */
    public function getIndex(Request $request)
    {
        $boardId = '';
        if ($request->has('boardId')) {
            $boardId = $request->input('boardId');
        }
        $this->prepareBoardData($boardId);

        return $this->view->render('index');
    }

    /**
     * @param string $boardId
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function prepareBoardData($boardId = '')
    {
        $this->view->assignMultiple(
            [
                'boardMenu' => $this->makeBoardMenu($boardId),
            ]
        );
        if ($boardId === 'bugfix' || $boardId === 'feature' || $boardId === 'task') {
            $query = $this->getBoardQuery($boardId);
            $boardData = $this->getBoardData($query);
            $this->view->assign('board', $boardData);
        }
    }

    /**
     * @param string $boardId
     *
     * @return array
     */
    protected function getBoardQuery($boardId)
    {
        $query = [
            'bool' => [
                'must' => [
                    [
                        'query_string' => [
                            'default_field' => 'subject',
                            'query' => ''.strtoupper($boardId).'',
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
                        'query_string' => [
                            'default_field' => 'review.subject',
                            'query' => 'WIP',
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
     * @throws \Elastica\Exception\InvalidException
     */
    protected function makeBoardMenu($active = '')
    {
        $out = [
            [
                'id' => 'bugfix',
                'count' => $this->getTicketByBoardQuery($this->getBoardQuery('bugfix')),
                'name' => 'Bugfixes',
                'active' => 'bugfix' === $active,
            ],
            [
                'id' => 'task',
                'count' => $this->getTicketByBoardQuery($this->getBoardQuery('task')),
                'name' => 'Tasks',
                'active' => 'task' === $active,
            ],
            [
                'id' => 'feature',
                'count' => $this->getTicketByBoardQuery($this->getBoardQuery('feature')),
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
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getTicketByBoardQuery(array $query)
    {
        $fullRequest = [
            'query' => $query,
            'size' => 0,
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('review');
        $resultSet = $search->search();

        return $resultSet->getTotalHits();
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
        $this->view->assign('boardConfig', json_encode($query, JSON_PRETTY_PRINT));
        $out = [
            'missesBoth' => [],
            'missesCodeReview' => [],
            'missesTesting' => [],
            'hasPlusOne' => [],
            'hasPlusTwo' => [],
            'blocked' => [],
            'unclear' => [],
        ];
        $fullRequest = [
            'query' => $query,
            'size' => 500,
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('review');
        $resultSet = $search->search();
        foreach ($resultSet->getResults() as $review) {
            $getBoardGroup = $this->defineBoardGroup($review->getSource());
            $out[$getBoardGroup][] = $review->getSource();
        }

        return $out;
    }

    /**
     * Defines board columns.
     *
     * @param array $review
     *
     * @return string
     */
    protected function defineBoardGroup(array $review)
    {
        switch (true) {
            case
                $review['positive_reviews'] === 0
                && $review['positive_verifications'] === 0
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0
            :
                $metaStatus = 'missesBoth';
                break;
            case
                $review['positive_reviews'] === 1
                && $review['positive_verifications'] === 0
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0
            :
                $metaStatus = 'missesTesting';
                break;
            case
                $review['positive_reviews'] === 0
                && $review['positive_verifications'] === 1
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0
            :
                $metaStatus = 'missesCodeReview';
                break;
            case
                $review['positive_reviews'] === 1
                && $review['positive_verifications'] === 1
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0
            :
                $metaStatus = 'hasPlusOne';
                break;
            case
                $review['positive_reviews'] === 2
                && $review['positive_verifications'] === 2
                && $review['negative_reviews'] === 0
                && $review['negative_verifications'] === 0
            :
                $metaStatus = 'hasPlusTwo';
                break;
            default:
                $metaStatus = 'blocked';
        }

        return $metaStatus;
    }
}
