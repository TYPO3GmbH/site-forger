<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Controllers;

use App\Elastic\Connection;
use Illuminate\Http\Request;

/**
 * Class UtilityController.
 */
class UtilityController extends Controller
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * Show the index screen to the user.
     *
     * @return string
     */
    public function getRst(): string
    {
        $tags = [
            'Backend',
            'CLI',
            'Database',
            'FAL',
            'FlexForm',
            'Fluid',
            'Frontend',
            'LocalConfiguration',
            'JavaScript',
            'PHP-API',
            'RTE',
            'TCA',
            'TSConfig',
            'TypoScript',
        ];
        sort($tags);

        $extensionKeys = [
            'documentation',
            'form',
            'opendocs',
            'setup',
            'extbase',
            'frontend',
            'recordlist',
            'sys_action',
            'extensionmanager',
            'impexp',
            'recycler',
            'sys_note',
            'feedit',
            'indexed_search',
            'redirects',
            't3editor',
            'about',
            'felogin',
            'info',
            'reports',
            'taskcenter',
            'backend',
            'filelist',
            'install',
            'rsaauth',
            'tstemplate',
            'belog',
            'filemetadata',
            'lang',
            'rte_ckeditor',
            'viewpage',
            'beuser',
            'fluid',
            'linkvalidator',
            'saltedpasswords',
            'workspaces',
            'core',
            'fluid_styled_content',
            'lowlevel',
            'scheduler'
        ];
        sort($extensionKeys);
        $this->view->assign('tags', $tags);
        $this->view->assign('extensionKeys', $extensionKeys);

        return $this->view->render('rst');
    }

    /**
     * Show the index screen to the user.
     *
     * @return string
     */
    public function getHelp(): string
    {
        return $this->view->render('help');
    }

    /**
     * Show the index screen to the user.
     *
     * @return string
     */
    public function getCalendar(): string
    {
        return $this->view->render('calendar');
    }

    /**
     * @param Connection $connection
     * @param Request $request
     *
     * @return string
     */
    public function getIssue(Connection $connection, Request $request): string
    {
        $this->connection = $connection;
        $issueId = 0;
        if ($request->has('issueId')) {
            $issueId = $request->input('issueId');
        }
        $issueData = $this->findIssue($issueId);

        return json_encode($issueData);
    }

    /**
     * @param int $issueId
     *
     * @return array
     */
    protected function findIssue($issueId): array
    {
        $index = $this->connection->getElasticIndex();
        $forger = $index->getType('issue');
        try {
            $res = $forger->getDocument($issueId);
            $data = $res->getData();
        } catch (\Exception $e) {
            $data = [
                'error' => 'Issue #' . $issueId . ' was not found.' . $e->getMessage(),
            ];
        }

        return $data;
    }
}
