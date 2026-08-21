<?php

namespace App\Services\Products;

use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\User;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Validation\ValidationException;

class ProductService
{
    private const SEARCHABLE_COLUMNS = [
        'name',
        'description',
        'sku',
        'category.name',
    ];

    private const SKU_PREFIX = 'SKU-';

    private const SKU_PAD_LENGTH = 5;

    public function list(
        ListQuery $query,
        ?int $categoryId = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
    ): LengthAwarePaginator {
        $builder = Product::query()
            ->with(['category', 'details.attribute'])
            ->select('products.*')
            ->selectSub(Product::stockSubquery(), 'stock')
            ->orderBy('name');

        if ($categoryId !== null) {
            $builder->where('category_id', $categoryId);
        }

        if ($minPrice !== null) {
            $builder->where('sale_price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $builder->where('sale_price', '<=', $maxPrice);
        }

        return SearchablePaginator::paginate($builder, $query, self::SEARCHABLE_COLUMNS);
    }

    public function find(Product $product): Product
    {
        $product->load(['category', 'details.attribute']);
        $product->setAttribute('stock', $product->stock);

        return $product;
    }

    public function create(array $data, User $author): Product
    {
        return DB::transaction(function () use ($data, $author) {
            $product = Product::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'sale_price' => $data['sale_price'],
                'sku' => $this->nextSku(),
                'category_id' => $data['category_id'],
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);

            $this->syncDetails($product, $data['details'] ?? [], $author);

            return $this->find($product->fresh());
        });
    }

    public function update(Product $product, array $data, User $author): Product
    {
        return DB::transaction(function () use ($product, $data, $author) {
            $product->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'sale_price' => $data['sale_price'],
                'category_id' => $data['category_id'],
                'updated_by' => $author->id,
            ]);

            if (array_key_exists('details', $data)) {
                $this->syncDetails($product, $data['details'] ?? [], $author);
            }

            return $this->find($product->fresh());
        });
    }

    public function delete(Product $product): void
    {
        if ($product->purchaseDetails()->exists()) {
            throw new ConflictHttpException(
                'No se puede eliminar este producto porque tiene compras asociadas.',
            );
        }

        if ($product->movements()->exists()) {
            throw new ConflictHttpException(
                'No se puede eliminar este producto porque tiene movimientos de inventario.',
            );
        }

        if (OrderDetail::query()->where('product_id', $product->id)->exists()) {
            throw new ConflictHttpException(
                'No se puede eliminar este producto porque aparece en pedidos.',
            );
        }

        $product->delete();
    }

    public function deleteDetail(Product $product, ProductDetail $detail): void
    {
        if ($detail->product_id !== $product->id) {
            throw ValidationException::withMessages([
                'detail' => ['El detalle no pertenece a este producto.'],
            ]);
        }

        $detail->delete();
    }

    /** SKU correlativo único generado solo en backend. */
    private function nextSku(): string
    {
        $latest = Product::query()
            ->where('sku', 'like', self::SKU_PREFIX.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('sku');

        $sequence = 1;

        if (is_string($latest) && preg_match('/^'.preg_quote(self::SKU_PREFIX, '/').'(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        do {
            $sku = self::SKU_PREFIX.str_pad((string) $sequence, self::SKU_PAD_LENGTH, '0', STR_PAD_LEFT);
            $exists = Product::query()->where('sku', $sku)->exists();
            $sequence++;
        } while ($exists);

        return $sku;
    }

    /**
     * @param  array<int, array{id?: int|null, attribute_id: int, value: string}>  $details
     */
    private function syncDetails(Product $product, array $details, User $author): void
    {
        $keepIds = [];
        $seenAttributes = [];

        foreach ($details as $detail) {
            $attributeId = (int) $detail['attribute_id'];

            if (isset($seenAttributes[$attributeId])) {
                throw ValidationException::withMessages([
                    'details' => ['No se puede repetir el mismo atributo en un producto.'],
                ]);
            }

            $seenAttributes[$attributeId] = true;

            $payload = [
                'attribute_id' => $attributeId,
                'value' => $detail['value'],
                'updated_by' => $author->id,
            ];

            if (! empty($detail['id'])) {
                $existing = ProductDetail::query()
                    ->where('product_id', $product->id)
                    ->where('id', $detail['id'])
                    ->first();

                if ($existing === null) {
                    throw ValidationException::withMessages([
                        'details' => ['Uno de los detalles no pertenece al producto.'],
                    ]);
                }

                $existing->update($payload);
                $keepIds[] = $existing->id;

                continue;
            }

            $created = $product->details()->create([
                ...$payload,
                'created_by' => $author->id,
            ]);
            $keepIds[] = $created->id;
        }

        $product->details()
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
            ->when($keepIds === [], fn ($q) => $q)
            ->delete();
    }
}
