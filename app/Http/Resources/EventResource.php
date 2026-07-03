<?php

namespace App\Http\Resources;

use App\Models\IdeHelperEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IdeHelperEvent
 */
class EventResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array
     */
    public function toArray($request): array
    {
        switch ($this->type) {
            case 'appointment':
                return [
                    'id' => $this->id,
                    'date' => $this->date,
                    'time' => $this->time->format('H:i'),
                    'duration' => $this->duration->format('H:i'),
                    'type' => $this->type,
                    'doctor' => new DoctorResource($this->doctor),
                    'patient' => new PatientResource($this->event->patient),
                    'plan' => new PlanResource($this->event->plan),
                    'appointment_type' => $this->event->type,
                    'payments' => $this->when(!$request->is('*/payments'), PaymentResource::collection($this->event->payments)),
                    'comment' => $this->event->comment,
                    'status' => $this->event->status,
                    'created_at' => $this->created_at,
                    'updated_at' => $this->updated_at,
                ];

            case 'blocked-time':
                return [
                    'id' => $this->event->id,
                    'date' => $this->date,
                    'time' => $this->time->format('H:i'),
                    'duration' => $this->duration->format('H:i'),
                    'type' => $this->type,
                    'reason' => $this->event->reason,
                    'created_at' => $this->created_at,
                    'updated_at' => $this->updated_at,
                ];
        }
    }
}
