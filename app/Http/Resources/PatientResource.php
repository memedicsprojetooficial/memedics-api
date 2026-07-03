<?php

namespace App\Http\Resources;

use App\Models\IdeHelperPatient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IdeHelperPatient
 */
class PatientResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'birthday' => $this->birthday,
            'gender' => $this->gender,
            'document' => $this->document,
            'address' => new AddressResource($this->address),
            'phone' => $this->phone,
            'phone2' => $this->phone2,
            'email' => $this->email,
            'plan_id' => $this->plan_id,
            'plan' => $this->whenLoaded('plan'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}
