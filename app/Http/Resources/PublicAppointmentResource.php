<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicAppointmentResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'date' => $this->event->date,
            'time' => $this->event->time->format('H:i'),
            'duration' => $this->event->duration->format('H:i'),
            'doctor_name' => $this->event->doctor->user->name ?? null,
            'doctor_specialty' => $this->event->doctor->specialty->name ?? null,
            'unit_name' => $this->event->doctor->unitAddress->unit_name ?? null,
            'patient_first_name' => $this->patient ? explode(' ', trim($this->patient->name))[0] : null,
            'status' => $this->status,
        ];
    }
}
