<?php

namespace App\Http\Resources;

use App\Models\Doctor;
use Illuminate\Http\Request;
use App\Http\Resources\DoctorInformationResource;
use App\Http\Resources\DoctorPlanResource;
use App\Http\Resources\WorkTimeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Doctor
 */
class DoctorResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'company_name' => $this->user->company_name,
            'email' => $this->user->email,
            'role' => $this->user->type,
            'admin' => $this->user->admin,
            'specialty' => $this->specialty,
            'cpf' => $this->cpf,
            'phone' => $this->phone,
            'council_type' => $this->council_type,
            'council_number' => $this->council_number,
            'unit_addresses_id' => $this->unit_addresses_id,
            'show_in_bot' => $this->show_in_bot,
            'work_times' => WorkTimeResource::collection($this->workTimes),
            'plans' => DoctorPlanResource::collection($this->plans),
            'information' => $this->when(
                $this->relationLoaded('information'),
                fn() => DoctorInformationResource::collection($this->information)
            ),
            'csat_average' => $this->when(
                $this->relationLoaded('csatResponses'),
                fn() => $this->csatResponses->count() > 0
                    ? round($this->csatResponses->avg('score'), 1)
                    : null
            ),
            'csat_count' => $this->when(
                $this->relationLoaded('csatResponses'),
                fn() => $this->csatResponses->count()
            ),
            'schedule_config' => $this->when(
                $this->relationLoaded('scheduleConfig'),
                fn() => new DoctorScheduleConfigResource($this->scheduleConfig)
            ),
        ];
    }
}
