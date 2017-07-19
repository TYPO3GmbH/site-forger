<?php

namespace App;

use Backpack\CRUD\CrudTrait;
use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    use CrudTrait;

    protected $table = 'issues';

    public $timestamps = true;

    protected $primaryKey = 'id';

    protected $fillable = ['issue_number', 'junior_id'];

    public function junior()
    {
        return $this->belongsTo('App\Junior');
    }
}
