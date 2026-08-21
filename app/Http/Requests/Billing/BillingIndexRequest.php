<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\Shared\PaginatedIndexRequest;

class BillingIndexRequest extends PaginatedIndexRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'document_type' => ['nullable', 'string'],
            'document_status' => ['nullable', 'string'],
            'sunat_status' => ['nullable', 'string'],
            'series' => ['nullable', 'string', 'max:8'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'min_total' => ['nullable', 'numeric', 'min:0'],
            'max_total' => ['nullable', 'numeric', 'min:0'],
            'external_reference' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'document_type' => $this->blankToNull($this->validated('document_type')),
            'document_status' => $this->blankToNull($this->validated('document_status')),
            'sunat_status' => $this->blankToNull($this->validated('sunat_status')),
            'series' => $this->blankToNull($this->validated('series')),
            'date_from' => $this->blankToNull($this->validated('date_from')),
            'date_to' => $this->blankToNull($this->validated('date_to')),
            'min_total' => $this->validated('min_total'),
            'max_total' => $this->validated('max_total'),
            'external_reference' => $this->blankToNull($this->validated('external_reference')),
        ];
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }
}
