<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\IdeHelperEvent;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IdeHelperEvent
 */
class EventResource extends JsonResource
{
    /**
     * Relacionamentos que toArray() lê, para carregar com with() em listas de eventos (agenda,
     * histórico do paciente) sem N+1. Só os agendamentos têm relacionamentos a carregar.
     */
    public static function relations(): array
    {
        return [
            ...DoctorResource::relationsUnder('doctor'),
            'event' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Appointment::class => ['patient.address', 'plan', 'payments.appointment.patient'],
            ]),
        ];
    }

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
