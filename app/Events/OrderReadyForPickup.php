<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderReadyForPickup
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}
}
