<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Support\Inventory\PaymentStatus;
use App\Support\Orders\OrderStatus;
use Illuminate\Validation\Rule;

class OrderIndexRequest extends PaginatedIndexRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(OrderStatus::values())],
            'payment_status' => ['sometimes', 'nullable', 'string', Rule::in(PaymentStatus::values())],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'min_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function customerId(): ?int
    {
        $value = $this->validated('customer_id');

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
