<?php

namespace App\Events;

use App\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentArrivedAtDestination
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Shipment $shipment,
    ) {}
}
