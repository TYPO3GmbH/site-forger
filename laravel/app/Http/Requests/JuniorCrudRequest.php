<?php
namespace App\Http\Requests;

use Backpack\CRUD\app\Http\Requests\CrudRequest;

/**
 * Class JuniorCrudRequest
 */
class JuniorCrudRequest extends CrudRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            'name' => 'required|min:3|max:255',
        ];
    }
}
