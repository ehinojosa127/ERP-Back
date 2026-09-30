<?php

namespace App\Models;

use App\Support\Inventory\MovementType;
use App\Support\Orders\FulfillmentType;
use App\Support\Orders\OrderStatus;
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
     * Stock disponible = físico (movimientos) − reservado en pedidos
     * REGISTERED/PREPARING con cumplimiento STOCK.
     * Nunca se persiste ni se actualiza manualmente.
     */
    public function getStockAttribute(): int
    {
        if (array_key_exists('stock', $this->attributes)) {
            return (int) $this->attributes['stock'];
        }

        $physical = (int) (Movement::query()
            ->where('product_id', $this->id)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN quantity WHEN type = ? THEN -quantity ELSE 0 END), 0) as stock',
                [MovementType::IN, MovementType::OUT],
            )
            ->value('stock') ?? 0);

        $reserved = (int) (OrderDetail::query()
            ->where('order_details.product_id', $this->id)
            ->where('order_details.fulfillment_type', FulfillmentType::STOCK)
            ->whereHas('order', function ($query) {
                $query->whereIn('status', [OrderStatus::REGISTERED, OrderStatus::PREPARING]);
            })
            ->sum('order_details.quantity'));

        return max(0, $physical - $reserved);
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

    /**
     * Subconsulta de stock disponible (físico − reservado).
     * $excludeOrderId omite un pedido al validar (p. ej. al enviar o editar ese mismo pedido).
     */
    public static function stockSubquery(?int $excludeOrderId = null): \Illuminate\Database\Query\Builder
    {
        $bindings = [
            MovementType::IN,
            MovementType::OUT,
            FulfillmentType::STOCK,
            OrderStatus::REGISTERED,
            OrderStatus::PREPARING,
        ];

        $excludeSql = '';
        if ($excludeOrderId !== null) {
            $excludeSql = ' AND orders.id <> ?';
            $bindings[] = $excludeOrderId;
        }

        return DB::table(DB::raw('(SELECT 1) as available_stock'))
            ->selectRaw(
                'CASE
                    WHEN (
                        COALESCE((
                            SELECT SUM(
                                CASE
                                    WHEN m.type = ? THEN m.quantity
                                    WHEN m.type = ? THEN -m.quantity
                                    ELSE 0
                                END
                            )
                            FROM movements m
                            WHERE m.product_id = products.id
                        ), 0)
                        -
                        COALESCE((
                            SELECT SUM(od.quantity)
                            FROM order_details od
                            INNER JOIN orders ON orders.id = od.order_id
                            WHERE od.product_id = products.id
                              AND od.fulfillment_type = ?
                              AND orders.status IN (?, ?)
                              '.$excludeSql.'
                        ), 0)
                    ) < 0 THEN 0
                    ELSE (
                        COALESCE((
                            SELECT SUM(
                                CASE
                                    WHEN m.type = ? THEN m.quantity
                                    WHEN m.type = ? THEN -m.quantity
                                    ELSE 0
                                END
                            )
                            FROM movements m
                            WHERE m.product_id = products.id
                        ), 0)
                        -
                        COALESCE((
                            SELECT SUM(od.quantity)
                            FROM order_details od
                            INNER JOIN orders ON orders.id = od.order_id
                            WHERE od.product_id = products.id
                              AND od.fulfillment_type = ?
                              AND orders.status IN (?, ?)
                              '.$excludeSql.'
                        ), 0)
                    )
                END',
                array_merge($bindings, $bindings),
            );
    }
}
