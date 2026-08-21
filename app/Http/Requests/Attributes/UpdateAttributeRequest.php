<?php

namespace App\Http\Requests\Attributes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $attributeId = $this->route('attribute')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('attributes', 'name')->ignore($attributeId),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre'];
    }
}
