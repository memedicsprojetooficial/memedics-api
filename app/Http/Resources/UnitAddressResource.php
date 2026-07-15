<?php

namespace App\Http\Resources;

use App\Models\UnitAddress;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UnitAddress
 */
class UnitAddressResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'company_id'     => $this->company_id,
            'company'        => $this->whenLoaded('company'),
            'unit_name'      => $this->unit_name,
            'street'         => $this->street,
            'number'         => $this->number,
            'complementary'  => $this->complementary,
            'neighborhood'   => $this->neighborhood,
            'city'           => $this->city,
            'state'          => $this->state,
            'zip_code'               => $this->zip_code,
            'evolution_instance_id'  => $this->evolution_instance_id,
            'business_hours'         => UnitBusinessHourResource::collection($this->businessHours),
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
