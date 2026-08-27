<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Concerns\PaymentValidationRules;
use App\Support\Billing\DocumentKind;
use App\Support\Billing\PaymentCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderPaymentRequest extends FormRequest
{
    use PaymentValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->paymentFieldRules(),
            'concept' => ['nullable', 'string', 'max:1000'],
            'emit_document' => ['sometimes', 'boolean'],
            'document_kind' => [
                'required_if:emit_document,true,1',
                'nullable',
                'string',
                Rule::in(DocumentKind::issuableFromOrder()),
            ],
            'series' => ['nullable', 'string', 'size:4'],
            'payment_condition' => ['nullable', 'string', Rule::in(PaymentCondition::values())],
        ];
    }

    public function attributes(): array
    {
        return [
            ...$this->paymentFieldAttributes(),
            'concept' => 'concepto',
            'emit_document' => 'emitir comprobante',
            'document_kind' => 'tipo de comprobante',
            'payment_condition' => 'condición de pago',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('emit_document')) {
            $this->merge([
                'emit_document' => filter_var($this->input('emit_document'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? $this->input('emit_document'),
            ]);
        }
    }
}
