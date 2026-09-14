<?php

namespace App\Events;

use App\Models\OrderPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPaymentConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly OrderPayment $payment,
    ) {}
}
