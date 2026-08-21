<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'observations',
        'fulfillment_type',
        'supplier_id',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'subtotal',
        'display_name',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    public function getSubtotalAttribute(): float
    {
        return round($this->quantity * (float) $this->unit_price, 2);
    }

    /** Nombre visible: producto de inventario si existe; si no, product_name. */
    public function getDisplayNameAttribute(): string
    {
        if ($this->relationLoaded('product') && $this->product !== null) {
            return (string) $this->product->name;
        }

        return trim((string) $this->product_name);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
