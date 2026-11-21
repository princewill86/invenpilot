<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['received', 'adjustment', 'damaged', 'supplier_return', 'customer_return'])],
            'quantity' => ['required', 'regex:/^-?[0-9]{1,13}(?:\.[0-9]{1,3})?$/D', 'not_in:0'],
            'reference' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
