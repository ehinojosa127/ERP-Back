<?php

namespace App\Http\Requests\Shared;

use App\Support\Query\ListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Petición común a todos los listados: valida paginación y término de búsqueda
 * en un solo sitio para que ningún endpoint repita esas reglas.
 */
class PaginatedIndexRequest extends FormRequest
{
    private const FIRST_PAGE = 1;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:'.self::FIRST_PAGE],
            'per_page' => [
                'sometimes', 'integer',
                'min:1',
                'max:'.config('pagination.max_page_size'),
            ],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function toListQuery(): ListQuery
    {
        $search = $this->validated('search');

        return new ListQuery(
            search: is_string($search) ? trim($search) : null,
            page: (int) ($this->validated('page') ?? self::FIRST_PAGE),
            perPage: (int) ($this->validated('per_page') ?? config('pagination.default_page_size')),
        );
    }
}
