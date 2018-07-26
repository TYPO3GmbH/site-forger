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
