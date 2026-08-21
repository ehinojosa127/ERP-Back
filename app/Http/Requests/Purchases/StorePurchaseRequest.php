<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_date' => ['required', 'date'],
            'observations' => ['nullable', 'string'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.product_id' => ['required', 'integer', 'exists:products,id', 'distinct'],
            'details.*.quantity' => ['required', 'integer', 'min:1'],
            'details.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'document_type' => ['nullable', 'string', 'max:40'],
            'document_series' => ['nullable', 'string', 'max:20'],
            'document_number' => ['nullable', 'string', 'max:20'],
            'document_issue_date' => ['nullable', 'date'],
            'document_amount' => ['nullable', 'numeric', 'min:0'],
            'document_observations' => ['nullable', 'string'],
            'document_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'proveedor',
            'purchase_date' => 'fecha de compra',
            'observations' => 'observaciones',
            'details' => 'detalle de compra',
            'details.*.product_id' => 'producto',
            'details.*.quantity' => 'cantidad',
            'details.*.unit_cost' => 'costo unitario',
            'document_type' => 'tipo de comprobante',
            'document_series' => 'serie del comprobante',
            'document_number' => 'número del comprobante',
            'document_file' => 'archivo del comprobante',
        ];
    }
}
