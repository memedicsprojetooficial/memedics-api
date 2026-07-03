<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->event->id,
            'date' => $this->event->date,
            'time' => $this->event->time->format('H:i'),
            'duration' => $this->event->duration->format('H:i'),
            'doctor' => new DoctorResource($this->event->doctor),
            'patient' => new PatientResource($this->patient),
            'plan' => new PlanResource($this->plan),
            'appointment_type' => $this->type,
            'payments' => $this->when(!$request->is('*/payments'), PaymentResource::collection($this->payments)),
            'report' => $this->when(!$request->is('*/medical-history'), new ReportResource($this->medicalReport)),
            'comment' => $this->comment,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
