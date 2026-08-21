<?php

namespace App\Http\Requests\Suppliers;

use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Support\Suppliers\SupplierKind;
use Illuminate\Validation\Rule;

/** Listado de proveedores: paginación y búsqueda comunes más el filtro de tipo. */
class SupplierIndexRequest extends PaginatedIndexRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'kind' => ['sometimes', 'nullable', Rule::in(SupplierKind::values())],
        ];
    }

    public function kind(): ?string
    {
        $kind = $this->validated('kind');

        return is_string($kind) && $kind !== '' ? $kind : null;
    }
}
