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
            'model' => 'App\Junior'
        ]);
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