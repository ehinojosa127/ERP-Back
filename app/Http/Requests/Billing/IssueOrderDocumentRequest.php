<?php

namespace App\Http\Requests\Billing;

use App\Support\Billing\DocumentKind;
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
        ];
    }
}
