<?php

namespace App;

use Backpack\CRUD\CrudTrait;
use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    use CrudTrait;

    protected $table = 'issues';

    protected $fillable = ['issue_number', 'junior_id'];

    public function junior()
    {
        return $this->belongsTo(Junior::class);
    }
}
