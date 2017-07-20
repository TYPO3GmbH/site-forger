<?php

namespace App;

use Backpack\CRUD\CrudTrait;
use Illuminate\Database\Eloquent\Model;

class Junior extends Model
{
    use CrudTrait;

    protected $table = 'juniors';

    protected $fillable = ['name'];

    public function issues()
    {
        return $this->hasMany(Issue::class);
    }
}
