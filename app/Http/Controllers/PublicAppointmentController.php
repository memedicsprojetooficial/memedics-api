<?php

namespace App\Http\Controllers;

use App\Events\ScheduleUpdated;
use App\Http\Resources\PublicAppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;

class PublicAppointmentController extends Controller
{
    /**
     * Status considerados finais — nenhuma ação do paciente deve mais alterá-los.
     * in_consultation, completed, cancelled, no_show, not_attended, switched_off
     */
    private const TERMINAL_STATUSES = [5, 6, 7, 8, 9, 10];

    public function show(string $token): JsonResponse
    {
        $appointment = $this->findByToken($token);

        return response()->json(['data' => new PublicAppointmentResource($appointment)]);
    }

    public function confirm(string $token): JsonResponse
    {
        $appointment = $this->findByToken($token);
        $this->guardActionable($appointment);

        $appointment->update(['status' => 2]); // confirmed
        ScheduleUpdated::dispatch($appointment->event->doctor);

        return response()->json(['data' => new PublicAppointmentResource($appointment->fresh())]);
    }

    public function cancel(string $token): JsonResponse
    {
        $appointment = $this->findByToken($token);
        $this->guardActionable($appointment);

        $appointment->update(['status' => 7]); // cancelled
        ScheduleUpdated::dispatch($appointment->event->doctor);

        return response()->json(['data' => new PublicAppointmentResource($appointment->fresh())]);
    }

    private function findByToken(string $token): Appointment
    {
        return Appointment::where('public_token', $token)->firstOrFail();
    }

    private function guardActionable(Appointment $appointment): void
    {
        if (in_array($appointment->status, self::TERMINAL_STATUSES, true)) {
            abort(409, 'Este agendamento não pode mais ser alterado.');
        }
    }
}
