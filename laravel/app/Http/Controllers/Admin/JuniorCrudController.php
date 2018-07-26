<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Requests\JuniorCrudRequest as StoreRequest;

// VALIDATION: change the requests to match your own file names if you need form validation
use App\Http\Requests\JuniorCrudRequest as UpdateRequest;
use App\Junior;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Illuminate\Http\RedirectResponse;

/**
 * Class JuniorCrudController
 */
class JuniorCrudController extends CrudController
{
    /**
     *
     */
    public function setup()
    {
        $this->crud->setModel(Junior::class);
        $this->crud->setRoute('admin/junior');
        $this->crud->setEntityNameStrings('junior', 'juniors');
        $this->crud->setFromDb();

        $this->crud->setColumnsDetails(['name'], ['label' => 'Junior name']);
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
