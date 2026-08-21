<?php

namespace App\Support\Query;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Búsqueda y paginación compartidas por todos los listados.
 *
 * Las columnas admiten notación de relación ("role.name"), de modo que un
 * módulo puede buscar también por campos de sus relaciones sin escribir su
 * propia consulta.
 */
final class SearchablePaginator
{
    private const RELATION_SEPARATOR = '.';

    /**
     * @param  array<int, string>  $searchableColumns
     * @return LengthAwarePaginator<int, \Illuminate\Database\Eloquent\Model>
     */
    public static function paginate(
        Builder $builder,
        ListQuery $query,
        array $searchableColumns,
    ): LengthAwarePaginator {
        if ($query->hasSearch() && $searchableColumns !== []) {
            self::applySearch($builder, (string) $query->search, $searchableColumns);
        }

        return $builder->paginate(
            perPage: $query->perPage,
            page: $query->page,
        );
    }

    /** @param  array<int, string>  $columns */
    public static function applySearch(Builder $builder, string $search, array $columns): void
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $builder->where(function (Builder $grouped) use ($term, $columns) {
            foreach ($columns as $column) {
                if (! str_contains($column, self::RELATION_SEPARATOR)) {
                    $grouped->orWhere($column, 'like', $term);

                    continue;
                }

                [$relation, $relatedColumn] = explode(self::RELATION_SEPARATOR, $column, 2);

                $grouped->orWhereHas(
                    $relation,
                    fn (Builder $related) => $related->where($relatedColumn, 'like', $term),
                );
            }
        });
    }
}
