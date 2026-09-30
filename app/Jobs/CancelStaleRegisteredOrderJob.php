<?php

namespace App\Jobs;

use App\Services\Orders\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CancelStaleRegisteredOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public function __construct(
        public readonly int $orderId,
    ) {}

    public function handle(OrderService $orders): void
    {
        $cancelled = $orders->cancelStaleRegisteredOrder($this->orderId);

        if ($cancelled) {
            Log::info('order.registered_expired', [
                'order_id' => $this->orderId,
                'source' => 'delayed_job',
            ]);
        }
    }
}
