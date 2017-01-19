<?php namespace App\Http\Controllers\Admin;

use Backpack\CRUD\app\Http\Controllers\CrudController;

// VALIDATION: change the requests to match your own file names if you need form validation
use App\Http\Requests\IssueCrudRequest as StoreRequest;
use App\Http\Requests\IssueCrudRequest as UpdateRequest;

class JuniorCrudController extends CrudController {

    public function setup() {
        $this->crud->setModel("App\Junior");
        $this->crud->setRoute("admin/junior");
        $this->crud->setEntityNameStrings('junior', 'juniors');

        $this->crud->setColumns(['name']);
        $this->crud->addField(
            [
                'name' => 'name',
                'label' => "Junior Name"
            ]
        );

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