<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderBillingReference extends Model
{
    protected $fillable = [
        'order_id',
        'order_payment_id',
        'document_kind',
        'origin',
        'billing_document_id',
        'sales_note_id',
        'series',
        'number',
        'full_number',
        'idempotency_key',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'order_payment_id');
    }

    public function salesNote(): BelongsTo
    {
        return $this->belongsTo(SalesNote::class);
    }
}
