<?php namespace App\Http\Controllers\Admin;

use Backpack\CRUD\app\Http\Controllers\CrudController;

// VALIDATION: change the requests to match your own file names if you need form validation
use App\Http\Requests\JuniorCrudRequest as StoreRequest;
use App\Http\Requests\JuniorCrudRequest as UpdateRequest;

class JuniorCrudController extends CrudController {

    public function setup() {
        $this->crud->setModel("App\Junior");
        $this->crud->setRoute("admin/junior");
        $this->crud->setEntityNameStrings('junior', 'juniors');
        $this->crud->setFromDb();

        $this->crud->setColumnsDetails(['name'], ['label' => 'Junior name']);
    }

    public function store(StoreRequest $request)
    {
        return parent::storeCrud();
    }

    public function update(UpdateRequest $request)
    {
        return parent::updateCrud();
    }
}