<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommerceRequest extends FormRequest
{
    public function rules(): array
    {
        $quantity = ['required', 'regex:/^[0-9]{1,8}(?:\.[0-9]{1,3})?$/D', 'numeric', 'gt:0'];
        if ($action = $this->route('action')) {
            return [
                'request_id' => ['required', 'uuid'],
                'reason' => ['required', 'string', 'max:1000'],
                'items' => [in_array($action, ['cancel', 'submit'], true) ? 'prohibited' : 'required', 'array', 'min:1', 'max:100', 'list'],
                'items.*' => ['array:product_id,quantity'],
                'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
                'items.*.quantity' => $quantity,
            ];
        }

        $isSale = $this->route('type') === 'sales';
        $money = ['regex:/^[0-9]{1,8}(?:\.[0-9]{1,4})?$/D'];

        return [
            'request_id' => ['required', 'uuid'],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'supplier_id' => [$isSale ? 'prohibited' : 'required', 'integer', 'min:1'],
            'status' => [$isSale ? 'prohibited' : 'required', 'in:draft,pending'],
            'discount' => ['nullable', ...$money],
            'additional_cost' => [$isSale ? 'prohibited' : 'nullable', ...$money],
            'payment_method' => [$isSale ? 'required' : 'prohibited', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100', 'list'],
            'items.*' => ['array:product_id,quantity,unit_price'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => $quantity,
            'items.*.unit_price' => ['required', ...$money],
        ];
    }
}
