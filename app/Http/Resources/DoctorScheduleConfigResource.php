<?php

namespace App\Http\Resources;

use App\Models\DoctorScheduleConfig;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DoctorScheduleConfig
 */
class DoctorScheduleConfigResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'doctor_id'           => $this->doctor_id,
            'slot_duration'       => $this->slot_duration,
            'slot_label_interval' => $this->slot_label_interval,
            'confirmation_days_before' => $this->confirmation_days_before,
        ];
    }
}
