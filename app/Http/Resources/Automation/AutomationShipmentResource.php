<?php

namespace App\Http\Resources\Automation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Shipment */
class AutomationShipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->relationLoaded('order') ? $this->order : $this->order()->with(['payments', 'details'])->first();
        $balance = $order !== null ? (float) $order->remaining_amount : 0.0;
        $includeKey = $balance <= 0.00001;

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => $order?->order_number,
            'agency' => $this->agency,
            'agency_destination' => $this->agency_destination,
            'destination' => $this->destination,
            'status' => $this->status,
            'shipment_date' => optional($this->shipment_date)?->toDateString(),
            'delivery_date' => optional($this->delivery_date)?->toDateString(),
            'shipping_key' => $includeKey ? $this->shipping_key : null,
            'order_balance' => $balance,
        ];
    }
}
