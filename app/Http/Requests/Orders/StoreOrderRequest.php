<?php

namespace App\Http\Requests\Orders;

use App\Support\Orders\FulfillmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'order_date' => ['required', 'date'],
            'observations' => ['nullable', 'string'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'details.*.product_name' => ['nullable', 'string', 'max:255'],
            'details.*.quantity' => ['required', 'integer', 'min:1'],
            'details.*.unit_price' => ['required', 'numeric', 'min:0'],
            'details.*.observations' => ['nullable', 'string'],
            'details.*.fulfillment_type' => [
                'required',
                'string',
                Rule::in(FulfillmentType::values()),
            ],
            'details.*.supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'document_kind' => ['nullable', 'string', Rule::in(['sales_note', 'receipt', 'invoice'])],
            'series' => ['nullable', 'string', 'size:4'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'cliente',
            'order_date' => 'fecha del pedido',
            'observations' => 'observaciones',
            'details' => 'detalle del pedido',
            'details.*.product_id' => 'producto',
            'details.*.product_name' => 'nombre del producto',
            'details.*.quantity' => 'cantidad',
            'details.*.unit_price' => 'precio unitario',
            'details.*.fulfillment_type' => 'tipo de cumplimiento',
            'details.*.supplier_id' => 'proveedor',
            'document_kind' => 'tipo de documento',
            'series' => 'serie',
        ];
    }
}
