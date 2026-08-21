<?php

namespace App\Http\Requests\Purchases;

use App\Support\Inventory\PurchaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(PurchaseStatus::values())],
            'movement_date' => [
                'nullable',
                'date',
                'required_if:status,'.PurchaseStatus::IN_WAREHOUSE,
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'estado',
            'movement_date' => 'fecha de ingreso a almacén',
        ];
    }
}
