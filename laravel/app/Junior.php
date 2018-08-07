<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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
