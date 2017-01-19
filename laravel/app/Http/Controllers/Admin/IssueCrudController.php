<?php namespace App\Http\Controllers\Admin;

use Backpack\CRUD\app\Http\Controllers\CrudController;

// VALIDATION: change the requests to match your own file names if you need form validation
use App\Http\Requests\IssueCrudRequest as StoreRequest;
use App\Http\Requests\IssueCrudRequest as UpdateRequest;

class IssueCrudController extends CrudController {

    public function setup() {
        $this->crud->setModel('App\Issue');
        $this->crud->setRoute('admin/issue');
        $this->crud->setEntityNameStrings('issue', 'issues');

        $this->crud->setColumns(['issue_id', 'junior']);
        $this->crud->addField(
            [
                'name' => 'issue_id',
                'label' => 'Issue ID'
            ]
        );
        $this->crud->addField(
            array(
                'label' => 'Junior',
                'type' => 'select2',
                'name' => 'junior_id', // the db column for the foreign key
                'entity' => 'junior', // the method that defines the relationship in your Model
                'attribute' => 'name', // foreign key attribute that is shown to user
                'model' => 'App\Junior'
            )
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