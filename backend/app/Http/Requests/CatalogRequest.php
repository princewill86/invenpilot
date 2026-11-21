<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogRequest extends FormRequest
{
    public function rules(): array
    {
        $business = $this->attributes->get('business')->id;
        $record = $this->route('record') === null ? null : (int) $this->route('record');
        $resource = $this->route('resource');
        $name = ['required', 'string', 'max:160'];
        $text = ['nullable', 'string', 'max:5000'];
        $money = ['required', 'regex:/^[0-9]{1,12}(?:\.[0-9]{1,4})?$/D'];

        return match ($resource) {
            'products' => [
                'name' => $name,
                'description' => $text,
                'sku' => ['required', 'string', 'max:100', Rule::unique('products')->where('business_id', $business)->ignore($record)],
                'barcode' => ['nullable', 'string', 'max:100'],
                'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_id', $business)],
                'unit' => ['required', 'string', 'max:40'],
                'purchase_cost' => $money,
                'selling_price' => $money,
                'minimum_stock' => ['required', 'regex:/^[0-9]{1,13}(?:\.[0-9]{1,3})?$/D'],
            ],
            'categories' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('categories')->where('business_id', $business)->ignore($record)],
                'description' => $text,
            ],
            'suppliers' => [
                'name' => $name,
                'contact_person' => ['nullable', 'string', 'max:160'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'address' => $text,
                'notes' => $text,
            ],
        };
    }
}
