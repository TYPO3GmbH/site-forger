<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Requests;

use Backpack\CRUD\app\Http\Requests\CrudRequest;

/**
 * Class IssueCrudRequest
 */
class IssueCrudRequest extends CrudRequest
{

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            'junior_id' => 'required',
            'issue_number' => 'required|min:4|max:6',
        ];
    }
}
