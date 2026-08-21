<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'details' => ['sometimes', 'array'],
            'details.*.id' => ['sometimes', 'nullable', 'integer', 'exists:product_details,id'],
            'details.*.attribute_id' => ['required_with:details', 'integer', 'exists:attributes,id'],
            'details.*.value' => ['required_with:details', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'description' => 'descripción',
            'sale_price' => 'precio de venta',
            'category_id' => 'categoría',
            'details.*.attribute_id' => 'atributo',
            'details.*.value' => 'valor',
        ];
    }
}
