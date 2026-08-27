<?php

namespace App\Http\Resources\Automation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
class AutomationProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'sale_price' => (float) $this->sale_price,
            'sku' => $this->sku,
            'stock' => (int) $this->stock,
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'attributes' => $this->whenLoaded('details', function () {
                return $this->details->map(fn ($detail) => [
                    'attribute' => $detail->attribute?->name,
                    'value' => $detail->value,
                ])->values()->all();
            }),
        ];
    }
}
