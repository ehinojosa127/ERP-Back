<?php

namespace App\Http\Requests\Billing;

use App\Support\Billing\DocumentKind;
use App\Support\Billing\PaymentCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueOrderDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_kind' => ['required', 'string', Rule::in(DocumentKind::issuableFromOrder())],
            'series' => ['nullable', 'string', 'size:4'],
            'payment_id' => ['nullable', 'integer', 'exists:order_payments,id'],
            'payment_condition' => ['nullable', 'string', Rule::in(PaymentCondition::values())],
        ];
    }
}
