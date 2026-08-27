<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboundWebhookDelivery extends Model
{
    protected $fillable = [
        'event',
        'idempotency_key',
        'resource_type',
        'resource_id',
        'status',
        'attempts',
        'last_attempt_at',
        'delivered_at',
        'last_error',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
