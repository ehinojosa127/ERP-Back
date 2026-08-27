<?php

namespace App\Jobs;

use App\Models\OutboundWebhookDelivery;
use App\Services\Automation\OutboundWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverN8nWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 120];

    public int $timeout = 30;

    public function __construct(
        public readonly int $deliveryId,
    ) {}

    public function handle(): void
    {
        $delivery = OutboundWebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        if (
            $delivery->status === OutboundWebhookDispatcher::STATUS_DELIVERED
            || $delivery->status === OutboundWebhookDispatcher::STATUS_SKIPPED
        ) {
            return;
        }

        $url = (string) config('services.n8n.webhook_url', '');
        $secret = (string) config('services.n8n.webhook_secret', '');
        $payload = $delivery->payload ?? [];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $delivery->forceFill([
            'attempts' => (int) $delivery->attempts + 1,
            'last_attempt_at' => now(),
        ])->save();

        if ($url === '') {
            Log::warning('n8n.webhook_url empty; marking delivery skipped', [
                'delivery_id' => $delivery->id,
                'event' => $delivery->event,
            ]);

            $delivery->forceFill([
                'status' => OutboundWebhookDispatcher::STATUS_SKIPPED,
                'delivered_at' => now(),
                'last_error' => 'N8N_WEBHOOK_URL no configurada',
            ])->save();

            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($secret !== '') {
            $headers['X-Webhook-Secret'] = $secret;
            $headers['X-Webhook-Signature'] = 'sha256='.hash_hmac('sha256', (string) $body, $secret);
        }

        try {
            $response = Http::timeout(25)
                ->withHeaders($headers)
                ->withBody((string) $body, 'application/json')
                ->post($url);

            if (! $response->successful()) {
                $error = 'HTTP '.$response->status();
                $delivery->forceFill([
                    'status' => OutboundWebhookDispatcher::STATUS_FAILED,
                    'last_error' => $error,
                ])->save();

                throw new \RuntimeException('n8n webhook failed: '.$error);
            }

            $delivery->forceFill([
                'status' => OutboundWebhookDispatcher::STATUS_DELIVERED,
                'delivered_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => OutboundWebhookDispatcher::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }
    }
}
