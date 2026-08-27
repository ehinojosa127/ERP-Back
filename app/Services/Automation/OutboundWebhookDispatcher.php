<?php

namespace App\Services\Automation;

use App\Jobs\DeliverN8nWebhookJob;
use App\Models\OutboundWebhookDelivery;
use Illuminate\Support\Facades\DB;

final class OutboundWebhookDispatcher
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        string $event,
        string $idempotencyKey,
        array $payload,
        ?string $resourceType = null,
        ?int $resourceId = null,
    ): ?OutboundWebhookDelivery {
        return DB::transaction(function () use ($event, $idempotencyKey, $payload, $resourceType, $resourceId) {
            $existing = OutboundWebhookDelivery::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === self::STATUS_DELIVERED) {
                return null;
            }

            if ($existing !== null && $existing->status === self::STATUS_SKIPPED) {
                return null;
            }

            $delivery = $existing ?? new OutboundWebhookDelivery([
                'idempotency_key' => $idempotencyKey,
            ]);

            $delivery->fill([
                'event' => $event,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'status' => self::STATUS_PENDING,
                'payload' => $payload,
                'last_error' => null,
            ]);
            $delivery->save();

            DeliverN8nWebhookJob::dispatch($delivery->id);

            return $delivery;
        });
    }
}
