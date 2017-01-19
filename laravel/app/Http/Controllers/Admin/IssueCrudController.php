<?php namespace App\Http\Controllers\Admin;

use Backpack\CRUD\app\Http\Controllers\CrudController;

// VALIDATION: change the requests to match your own file names if you need form validation
use App\Http\Requests\IssueCrudRequest as StoreRequest;
use App\Http\Requests\IssueCrudRequest as UpdateRequest;

class IssueCrudController extends CrudController {

    public function setup() {
        $this->crud->setModel("App\Issue");
        $this->crud->setRoute("admin/issue");
        $this->crud->setEntityNameStrings('issue', 'issues');

        $this->crud->setColumns(['issue_id', 'junior']);
        $this->crud->addField(
            [
                'name' => 'issue_id',
                'label' => "Issue ID"
            ]
        );
        $this->crud->addField(
            [
                'name' => 'junior',
                'label' => "Junior Email"
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