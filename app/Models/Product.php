<?php

namespace App\Models;

use App\Support\Inventory\MovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'sale_price',
        'sku',
        'category_id',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'stock',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
        ];
    }

    /**
     * Stock derivado exclusivamente de Movements.
     * Nunca se persiste ni se actualiza manualmente.
     */
    public function getStockAttribute(): int
    {
        if (array_key_exists('stock', $this->attributes)) {
            return (int) $this->attributes['stock'];
        }

        $row = Movement::query()
            ->where('product_id', $this->id)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN quantity WHEN type = ? THEN -quantity ELSE 0 END), 0) as stock',
                [MovementType::IN, MovementType::OUT],
            )
            ->first();

        return (int) ($row?->stock ?? 0);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(ProductDetail::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function purchaseDetails(): HasMany
    {
        return $this->hasMany(PurchaseDetail::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Subconsulta reutilizable para listados sin N+1. */
    public static function stockSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('movements')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN quantity WHEN type = ? THEN -quantity ELSE 0 END), 0)',
                [MovementType::IN, MovementType::OUT],
            )
            ->whereColumn('movements.product_id', 'products.id');
    }
}
