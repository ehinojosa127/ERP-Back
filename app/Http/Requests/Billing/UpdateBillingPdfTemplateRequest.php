<?php

namespace App\Http\Requests\Billing;

use App\Support\Billing\BillingPdfTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingPdfTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template' => ['required', 'string', Rule::in(BillingPdfTemplate::codes())],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'template' => 'plantilla PDF',
        ];
    }
}
