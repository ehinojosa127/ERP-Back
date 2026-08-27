<?php

namespace App\Http\Resources\Automation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Customer */
class AutomationCustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lastname' => $this->lastname,
            'dni' => $this->dni,
            'ruc' => $this->ruc,
            'legal_name' => $this->legal_name,
            'phone_number' => $this->phone_number,
            'city' => $this->city,
            'agency_destination' => $this->agency_destination,
            'address' => $this->address,
        ];
    }
}
