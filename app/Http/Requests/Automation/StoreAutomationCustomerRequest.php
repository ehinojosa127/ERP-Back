<?php

namespace App\Http\Requests\Automation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomationCustomerRequest extends FormRequest
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
            'phone_number' => ['required_without:phone', 'nullable', 'string', 'max:20'],
            'phone' => ['required_without:phone_number', 'nullable', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'agency_destination' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('phone') && ! $this->filled('phone_number')) {
            $this->merge(['phone_number' => $this->input('phone')]);
        }
    }
}
