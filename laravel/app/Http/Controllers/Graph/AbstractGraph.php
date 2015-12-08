<?php
namespace App\Http\Controllers\Graph;

use App\Elastic\Connection;
use Elastica as El;

/**
 * Class AbstractGraph
 *
 * @package WMDB\Forger\Graph
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
//        $chartSettings =
//            $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
//                'WMDB.Forger.Charts');
//        foreach ($chartSettings['Phases'] as $version => $phases) {
//            foreach ($phases as $phaseName => $dates) {
//                $out[] = [
//                    'fillAlpha' => 0.5,
//                    'date' => $dates['start'],
//                    'toDate' => $dates['end'],
//                    'label' => $phaseName,
//                    'position' => 'top',
//                    'inside' => true,
//                    'fillColor' => $chartSettings['Colors'][$phaseName]
//                ];
//            }
//        }

        return $out;
    }
}
