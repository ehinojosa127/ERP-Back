<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'agency',
        'shipment_date',
        'delivery_date',
        'shipping_key',
        'destination',
        'status',
        'agency_destination',
        'receipt_file_path',
        'receipt_file_name',
        'receipt_file_mime',
        'order_id',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'has_receipt',
    ];

    protected $hidden = [
        'receipt_file_path',
    ];

    protected function casts(): array
    {
        return [
            'shipment_date' => 'date',
            'delivery_date' => 'date',
        ];
    }

    public function getHasReceiptAttribute(): bool
    {
        return filled($this->attributes['receipt_file_path'] ?? null)
            || filled($this->attributes['receipt_file_name'] ?? null);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
