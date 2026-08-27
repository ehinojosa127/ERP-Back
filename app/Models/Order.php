<?php

namespace App\Models;

use App\Support\Inventory\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'observations',
        'status',
        'order_date',
        'customer_id',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
        ];
    }

    public function getTotalAmountAttribute(): float
    {
        if (array_key_exists('total_amount', $this->attributes)) {
            return round((float) $this->attributes['total_amount'], 2);
        }

        if ($this->relationLoaded('details')) {
            return round((float) $this->details->sum(
                fn (OrderDetail $detail) => $detail->quantity * (float) $detail->unit_price,
            ), 2);
        }

        return round((float) $this->details()->selectRaw(
            'COALESCE(SUM(quantity * unit_price), 0) as aggregate',
        )->value('aggregate'), 2);
    }

    public function getPaidAmountAttribute(): float
    {
        if (array_key_exists('paid_amount', $this->attributes)) {
            return round((float) $this->attributes['paid_amount'], 2);
        }

        if ($this->relationLoaded('payments')) {
            return round((float) $this->payments->sum('amount'), 2);
        }

        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, round($this->total_amount - $this->paid_amount, 2));
    }

    public function getPaymentStatusAttribute(): string
    {
        return PaymentStatus::fromAmounts($this->total_amount, $this->paid_amount);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /** Comprobante más reciente (compatibilidad). Preferir billingReferences(). */
    public function billingReference(): HasOne
    {
        return $this->hasOne(OrderBillingReference::class)->latestOfMany();
    }

    public function billingReferences(): HasMany
    {
        return $this->hasMany(OrderBillingReference::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Subconsulta del total calculado (quantity * unit_price). */
    public static function totalAmountSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('order_details')
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0)')
            ->whereColumn('order_details.order_id', 'orders.id');
    }
}
