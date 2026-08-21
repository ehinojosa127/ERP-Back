<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\Shared\PaginatedIndexRequest;

class ProductIndexRequest extends PaginatedIndexRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'min_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function categoryId(): ?int
    {
        $value = $this->validated('category_id');

        return $value !== null ? (int) $value : null;
    }

    public function minPrice(): ?float
    {
        $value = $this->validated('min_price');

        return $value !== null ? (float) $value : null;
    }

    public function maxPrice(): ?float
    {
        $value = $this->validated('max_price');

        return $value !== null ? (float) $value : null;
    }
}
