<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Backpack\CRUD\CrudTrait;

class Issue extends Model
{
    use CrudTrait;

    protected $table = 'issues';

    public $timestamps = true;

    protected $primaryKey = 'id';

    protected $fillable = ['issue_id', 'junior'];
}
