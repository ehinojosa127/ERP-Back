<?php

namespace App\Http\Requests\Concerns;

use App\Support\Inventory\PaymentMethod;
use Illuminate\Validation\Rule;

trait PaymentValidationRules
{
    /** @return array<string, mixed> */
    protected function paymentFieldRules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'integer', Rule::in(PaymentMethod::values())],
            'payment_date' => ['required', 'date'],
            'operation_number' => ['nullable', 'string', 'max:100'],
            'receipt_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    protected function paymentFieldAttributes(): array
    {
        return [
            'amount' => 'monto',
            'payment_method' => 'tipo de pago',
            'payment_date' => 'fecha de pago',
            'operation_number' => 'número de operación',
            'receipt_file' => 'comprobante de pago',
        ];
    }
}
