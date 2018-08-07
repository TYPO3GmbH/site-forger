<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Requests\IssueCrudRequest as StoreRequest;

// VALIDATION: change the requests to match your own file names if you need form validation
use App\Http\Requests\IssueCrudRequest as UpdateRequest;
use App\Issue;
use App\Junior;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Illuminate\Http\RedirectResponse;

/**
 * Class IssueCrudController
 */
class IssueCrudController extends CrudController
{
    /**
     *
     */
    public function setup()
    {
        $this->crud->setModel(Issue::class);
        $this->crud->setRoute('admin/issue');
        $this->crud->setEntityNameStrings('issue', 'issues');
        $this->crud->setFromDb();

        $this->crud->setColumnsDetails(['issue_number'], ['label' => 'Issue number']);
        $this->crud->setColumnsDetails(['junior_id'], [
            'label' => 'Junior',
            'type' => 'select',
            'entity' => 'junior',
            'attribute' => 'name'
        ]);
        $this->crud->addField([
            'label' => 'Junior',
            'type' => 'select2',
            'name' => 'junior_id',
            'entity' => 'junior',
            'attribute' => 'name',
            'model' => Junior::class
        ]);
    }

    /**
     * @param UpdateRequest $request
     *
     * @return RedirectResponse
     */
    public function store(StoreRequest $request) : RedirectResponse
    {
        return parent::storeCrud();
    }

    /**
     * @param UpdateRequest $request
     *
     * @return RedirectResponse
     */
    public function update(UpdateRequest $request) : RedirectResponse
    {
        return parent::updateCrud();
    }
}
