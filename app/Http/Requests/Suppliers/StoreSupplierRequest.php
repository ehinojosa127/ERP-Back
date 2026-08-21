<?php

namespace App\Http\Requests\Suppliers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Un proveedor es persona (name + lastname + dni) o empresa
     * (company_name + ruc). No hay columna `type`: se deduce de los datos.
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255', 'required_without:company_name'],
            'lastname' => ['nullable', 'string', 'max:255', 'required_with:name'],
            'company_name' => ['nullable', 'string', 'max:255', 'required_without:name'],
            'ruc' => [
                'nullable', 'string', 'digits:11', 'required_with:company_name',
                Rule::unique('suppliers', 'ruc'),
            ],
            'dni' => ['nullable', 'string', 'digits:8'],
            'phone_number' => ['required', 'string', 'digits_between:6,15'],
            'city' => ['required', 'string', 'max:255'],
        ];
    }
}
