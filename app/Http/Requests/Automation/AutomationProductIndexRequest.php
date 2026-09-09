<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\Shared\PaginatedIndexRequest;

class AutomationProductIndexRequest extends PaginatedIndexRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'min_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Un solo atributo: ?attribute=Color&attribute_value=Negro
            'attribute' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attribute_value' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Varios atributos: ?attributes[Color]=Negro&attributes[Talla]=10
            'attributes' => ['sometimes', 'nullable', 'array'],
            'attributes.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function categoryId(): ?int
    {
        $value = $this->validated('category_id');

        return $value !== null ? (int) $value : null;
    }

    public function categoryName(): ?string
    {
        $value = $this->validated('category');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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

    /**
     * @return array<string, string> mapa nombreAtributo => valor
     */
    public function attributeFilters(): array
    {
        $filters = [];

        $attrs = $this->validated('attributes');
        if (is_array($attrs)) {
            foreach ($attrs as $name => $value) {
                $name = trim((string) $name);
                $value = trim((string) $value);
                if ($name !== '' && $value !== '') {
                    $filters[$name] = $value;
                }
            }
        }

        $singleName = $this->validated('attribute');
        $singleValue = $this->validated('attribute_value');
        if (is_string($singleName) && trim($singleName) !== ''
            && is_string($singleValue) && trim($singleValue) !== '') {
            $filters[trim($singleName)] = trim($singleValue);
        }

        return $filters;
    }
}
