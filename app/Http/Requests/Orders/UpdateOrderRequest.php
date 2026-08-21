<?php

namespace App\Http\Requests\Orders;

class UpdateOrderRequest extends StoreOrderRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['customer_id'] = ['sometimes', 'required', 'integer', 'exists:customers,id'];
        $rules['order_date'] = ['sometimes', 'required', 'date'];
        $rules['details'] = ['sometimes', 'required', 'array', 'min:1'];

        return $rules;
    }
}
