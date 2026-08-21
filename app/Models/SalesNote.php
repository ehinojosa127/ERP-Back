<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesNote extends Model
{
    protected $fillable = [
        'series',
        'number',
        'full_number',
        'issue_date',
        'order_id',
        'customer_id',
        'customer_name',
        'customer_document',
        'subtotal',
        'total',
        'status',
        'observations',
        'items_snapshot',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'items_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
