<?php
namespace App\Http\Controllers\Graph;

use App\Elastic\Connection;
use Elastica as El;

/**
 * Class AbstractGraph
 */
class AbstractGraph
{
    protected $chartData = [];

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * AbstractGraph constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Makes the date string from elastic JS compatible
     *
     * @param string $in
     *
     * @return string
     */
    protected function fixTime($in)
    {
        $date = substr($in, 0, 10);
        $out = str_replace('/', '-', $date);

        return $out;
    }

    /**
     * @return string
     */
    public function render()
    {
        $this->getData();
        return json_encode($this->chartData);
    }

    /**
     * @return array
     */
    protected function getData()
    {
        return [];
    }

    /**
     * @return array
     */
    protected function getGuides()
    {
        $out = [];
        return $out;
    }
}
