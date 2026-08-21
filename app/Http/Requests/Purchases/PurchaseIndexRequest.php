<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Support\Inventory\PaymentStatus;
use App\Support\Inventory\PurchaseStatus;
use Illuminate\Validation\Rule;

class PurchaseIndexRequest extends PaginatedIndexRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(PurchaseStatus::values())],
            'payment_status' => ['sometimes', 'nullable', 'string', Rule::in(PaymentStatus::values())],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'min_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function supplierId(): ?int
    {
        $value = $this->validated('supplier_id');

        return $value !== null ? (int) $value : null;
    }

    public function status(): ?string
    {
        $value = $this->validated('status');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function paymentStatus(): ?string
    {
        $value = $this->validated('payment_status');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function purchaseDate(): ?string
    {
        $value = $this->validated('purchase_date');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function dateFrom(): ?string
    {
        $value = $this->validated('date_from');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function dateTo(): ?string
    {
        $value = $this->validated('date_to');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function minTotal(): ?float
    {
        $value = $this->validated('min_total');

        return $value !== null ? (float) $value : null;
    }

    public function maxTotal(): ?float
    {
        $value = $this->validated('max_total');

        return $value !== null ? (float) $value : null;
    }
}
