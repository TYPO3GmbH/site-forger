<?php

namespace App\ViewHelpers;

use App\Elastic\TrackerFilters;
use Illuminate\Support\Facades\Config;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class ForgelinkViewHelper extends AbstractViewHelper
{
    /**
     * Initialize arguments.
     *
     * @throws \TYPO3Fluid\Fluid\Core\ViewHelper\Exception
     *
     * @api
     */
    public function initializeArguments()
    {
        $this->registerArgument('year', 'string', '');
        $this->registerArgument('month', 'string', '');
        $this->registerArgument('statusId', 'string', '', false, 'o');
        $this->registerArgument('trackerFilters', 'TrackerFilters', 'Specifies the name of an anchor');
    }

    /**
     * @return string
     */
    public function render()
    {
        $projectId = Config::get('app.redmine.projectId');
        switch ($projectId) {
            case '1862':
                $redmineUrl = 'https://forge.typo3.org/projects/team-server-public/issues?set_filter=1';
                break;
            default:
                $redmineUrl = 'https://forge.typo3.org/projects/typo3cms-core/issues?set_filter=1';
        }
        $filtersString = $this->getFiltersString(
            $this->arguments['year'],
            $this->arguments['month'],
            $this->arguments['statusId'],
            $this->arguments['trackerFilters']
        );
        $content = $redmineUrl.$filtersString.'&f[]='.$this->getColumnsString().'&group_by=';

        return $content;
    }

    /**
     * @param string $year
     * @param string $month
     *
     * @return string
     */
    protected function getLastDayOfMonth($year = '', $month = '')
    {
        $dateString = $year.'-'.$month.'-16';

        return date('Y-m-t', strtotime($dateString));
    }

    /**
     * @return string
     */
    protected function getColumnsString()
    {
        $string = '';
        $columns = [
            'tracker',
            'status',
            'priority',
            'subject',
            'assigned_to',
            'category',
            'fixed_version',
        ];
        foreach ($columns as $columnName) {
            $string .= '&c[]='.$columnName;
        }

        return $string;
    }

    /**
     * @param string         $year
     * @param string         $month
     * @param string         $statusId
     * @param TrackerFilters $trackerFilters
     *
     * @return string
     */
    protected function getFiltersString($year, $month, $statusId, TrackerFilters $trackerFilters = null)
    {
        if (!$trackerFilters) {
            $trackerFilters = new TrackerFilters();
        }
        $end = $this->getLastDayOfMonth($year, $month);
        $string = '';
        $filters = [
            'status_id' => [
                'type' => '=',
                'values' => [
                    $statusId,
                ],
            ],
            'tracker_id' => [
                'type' => '=',
                'values' => [],
            ],
            'updated_on' => [
                'type' => '><',
                'values' => [
                    $year.'-'.$month.'-01',
                    $end,
                ],
            ],
        ];

        /*
         * Tracker Filters
         */
        if (!$trackerFilters->isFeature()) {
            $filters['tracker_id']['values'][] = '2';
        }
        if (!$trackerFilters->isTask()) {
            $filters['tracker_id']['values'][] = '4';
        }
        if (!$trackerFilters->isBug()) {
            $filters['tracker_id']['values'][] = '1';
        }
        if (!$trackerFilters->isEpic()) {
            $filters['tracker_id']['values'][] = '10';
        }
        if (!$trackerFilters->isStory()) {
            $filters['tracker_id']['values'][] = '6';
        }
        if ($statusId === 'o') {
            $filters['status_id']['type'] = 'o';
            $filters['status_id']['values'] = [];
        }
        foreach ($filters as $fieldName => $config) {
            $string .= '&f[]='.$fieldName;
            $string .= '&op['.$fieldName.']='.$config['type'];
            foreach ($config['values'] as $value) {
                $string .= '&v['.$fieldName.'][]='.$value;
            }
        }

        return $string;
    }
}
