<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Backpack\CRUD\CrudTrait;

class Junior extends Model
{
    use CrudTrait;

    protected $table = 'juniors';

    public $timestamps = true;

    protected $primaryKey = 'id';

    protected $fillable = ['name'];

    public function issues()
    {
        return $this->hasMany('App\Issue');
    }
}
