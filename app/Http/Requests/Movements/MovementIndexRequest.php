<?php

namespace App\Http\Requests\Movements;

use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use Illuminate\Validation\Rule;

class MovementIndexRequest extends PaginatedIndexRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'type' => ['sometimes', 'nullable', 'integer', Rule::in(MovementType::values())],
            'reference_type' => [
                'sometimes', 'nullable', 'string',
                Rule::in(MovementReferenceType::values()),
            ],
            'reference_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function productId(): ?int
    {
        $value = $this->validated('product_id');

        return $value !== null ? (int) $value : null;
    }

    public function type(): ?int
    {
        $value = $this->validated('type');

        return $value !== null ? (int) $value : null;
    }

    public function referenceType(): ?string
    {
        $value = $this->validated('reference_type');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function referenceId(): ?int
    {
        $value = $this->validated('reference_id');

        return $value !== null ? (int) $value : null;
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
}
