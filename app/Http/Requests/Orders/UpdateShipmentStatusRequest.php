<?php

namespace App\Http\Requests\Orders;

use App\Support\Orders\ShipmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ShipmentStatus::values())],
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'estado del envío',
        ];
    }
}
