<?php

namespace App\Http\Resources\Automation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OrderBillingReference */
class AutomationBillingDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'billing_document_id' => $this->billing_document_id,
            'document_kind' => $this->document_kind,
            'origin' => $this->origin,
            'series' => $this->series,
            'number' => $this->number,
            'full_number' => $this->full_number,
            'order_payment_id' => $this->order_payment_id,
            'payment_amount' => $this->whenLoaded('payment', fn () => $this->payment?->amount !== null
                ? (float) $this->payment->amount
                : null),
            'payment_concept' => $this->whenLoaded('payment', fn () => $this->payment?->concept),
        ];
    }
}
