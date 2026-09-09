<?php

namespace App\Services\Automation;

use App\Models\Product;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AutomationProductService
{
    private const SEARCHABLE_COLUMNS = [
        'name',
        'description',
        'sku',
        'category.name',
    ];

    /**
     * @param  array<string, string>  $attributeFilters  nombre atributo => valor
     */
    public function list(
        ListQuery $query,
        ?int $categoryId = null,
        ?string $categoryName = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        array $attributeFilters = [],
        bool $availableOnly = false,
    ): LengthAwarePaginator {
        $builder = Product::query()
            ->with(['category', 'details.attribute'])
            ->select('products.*')
            ->selectSub(Product::stockSubquery(), 'stock')
            ->orderBy('name');

        if ($categoryId !== null) {
            $builder->where('category_id', $categoryId);
        }

        if ($categoryName !== null) {
            $builder->whereHas(
                'category',
                fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($categoryName).'%']),
            );
        }

        if ($minPrice !== null) {
            $builder->where('sale_price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $builder->where('sale_price', '<=', $maxPrice);
        }

        foreach ($attributeFilters as $attributeName => $attributeValue) {
            $builder->whereHas('details', function (Builder $q) use ($attributeName, $attributeValue) {
                $q->whereRaw('LOWER(value) LIKE ?', ['%'.mb_strtolower($attributeValue).'%'])
                    ->whereHas(
                        'attribute',
                        fn (Builder $aq) => $aq->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($attributeName).'%']),
                    );
            });
        }

        if ($availableOnly) {
            $stockSub = Product::stockSubquery();
            $builder->whereRaw('('.$stockSub->toSql().') > 0', $stockSub->getBindings());
        }

        return SearchablePaginator::paginate($builder, $query, self::SEARCHABLE_COLUMNS);
    }

    /**
     * @return array{product_id: int, stock: int, sku: string|null, name: string}
     */
    public function stock(int $productId): array
    {
        $product = Product::query()
            ->select('products.*')
            ->selectSub(Product::stockSubquery(), 'stock')
            ->whereKey($productId)
            ->first();

        if ($product === null) {
            throw new NotFoundHttpException('Producto no encontrado.');
        }

        return [
            'product_id' => (int) $product->id,
            'name' => (string) $product->name,
            'sku' => $product->sku,
            'stock' => (int) $product->stock,
        ];
    }
}
