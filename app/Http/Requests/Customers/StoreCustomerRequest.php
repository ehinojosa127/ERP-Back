<?php

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'dni' => ['required', 'string', 'digits:8', Rule::unique('customers', 'dni')],
            'ruc' => ['nullable', 'string', 'digits:11', Rule::unique('customers', 'ruc')],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'digits_between:6,15'],
            'city' => ['required', 'string', 'max:255'],
            'agency_destination' => ['nullable', 'string', 'max:255'],
        ];
    }
}
