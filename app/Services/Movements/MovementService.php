<?php

namespace App\Services\Movements;

use App\Models\Movement;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MovementService
{
    private const SEARCHABLE_COLUMNS = [
        'reference_type',
        'product.name',
        'product.sku',
    ];

    /**
     * Solo consulta. Los movimientos de entrada se crean al pasar una compra
     * a IN_WAREHOUSE; no hay alta manual.
     */
    public function list(
        ListQuery $query,
        ?int $productId = null,
        ?int $type = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): LengthAwarePaginator {
        $builder = Movement::query()
            ->with(['product.category'])
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        if ($productId !== null) {
            $builder->where('product_id', $productId);
        }

        if ($type !== null) {
            $builder->where('type', $type);
        }

        if ($referenceType !== null) {
            $builder->where('reference_type', $referenceType);
        }

        if ($referenceId !== null) {
            $builder->where('reference_id', $referenceId);
        }

        if ($dateFrom !== null) {
            $builder->whereDate('movement_date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $builder->whereDate('movement_date', '<=', $dateTo);
        }

        return SearchablePaginator::paginate($builder, $query, self::SEARCHABLE_COLUMNS);
    }

    public function find(Movement $movement): Movement
    {
        return $movement->load(['product.category']);
    }
}
