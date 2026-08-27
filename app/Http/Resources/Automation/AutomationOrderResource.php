<?php

namespace App\Http\Resources\Automation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class AutomationOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'order_date' => optional($this->order_date)?->toDateString(),
            'observations' => $this->observations,
            'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'payment_status' => $this->payment_status,
            'shipment' => $this->when(
                $this->relationLoaded('shipment') && $this->shipment !== null,
                fn () => new AutomationShipmentResource($this->shipment->setRelation('order', $this->resource)),
            ),
        ];
    }
}
