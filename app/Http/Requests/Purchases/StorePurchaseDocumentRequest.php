<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['nullable', 'string', 'max:40'],
            'document_series' => ['nullable', 'string', 'max:20'],
            'document_number' => ['nullable', 'string', 'max:20'],
            'document_issue_date' => ['nullable', 'date'],
            'document_amount' => ['nullable', 'numeric', 'min:0'],
            'document_observations' => ['nullable', 'string'],
            'document_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
