<?php

namespace App\Http\Requests\Orders;

use App\Support\Orders\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(OrderStatus::values())],
            'shipment' => [
                Rule::requiredIf(fn () => $this->input('status') === OrderStatus::SHIPPED),
                'nullable',
                'array',
            ],
            'shipment.agency' => [
                Rule::requiredIf(fn () => $this->input('status') === OrderStatus::SHIPPED),
                'nullable',
                'string',
                'max:255',
            ],
            'shipment.shipment_date' => [
                Rule::requiredIf(fn () => $this->input('status') === OrderStatus::SHIPPED),
                'nullable',
                'date',
            ],
            'shipment.delivery_date' => ['nullable', 'date', 'after_or_equal:shipment.shipment_date'],
            'shipment.shipping_key' => [
                Rule::requiredIf(fn () => $this->input('status') === OrderStatus::SHIPPED),
                'nullable',
                'digits:4',
            ],
            'shipment.destination' => [
                Rule::requiredIf(fn () => $this->input('status') === OrderStatus::SHIPPED),
                'nullable',
                'string',
                'max:255',
            ],
            'shipment.agency_destination' => [
                Rule::requiredIf(fn () => $this->input('status') === OrderStatus::SHIPPED),
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'estado',
            'shipment' => 'envío',
            'shipment.agency' => 'agencia',
            'shipment.shipment_date' => 'fecha de envío',
            'shipment.delivery_date' => 'fecha de entrega',
            'shipment.shipping_key' => 'clave de envío',
            'shipment.destination' => 'destino',
            'shipment.agency_destination' => 'agencia destino',
        ];
    }
}
