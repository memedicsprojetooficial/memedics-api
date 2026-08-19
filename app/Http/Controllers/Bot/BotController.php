<?php

namespace App\Http\Controllers\Bot;

use App\Events\ScheduleUpdated;
use App\Http\Controllers\AppointmentLogController;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Event;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Specialty;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotController extends Controller
{
    // -------------------------------------------------------------------------
    // Especialidades
    // -------------------------------------------------------------------------

    public function specialties(Request $request): JsonResponse
    {
        $request->validate([
            'unit_address_id' => ['nullable', 'exists:unit_addresses,id'],
        ]);

        $specialties = Specialty::whereHas('doctors', function ($q) use ($request) {
            $q->where('show_in_bot', true);

            if ($request->filled('unit_address_id')) {
                $q->where('unit_addresses_id', $request->query('unit_address_id'));
            }
        })->orderBy('name')->get(['id', 'name']);

        return response()->json($specialties);
    }

    // -------------------------------------------------------------------------
    // Médicos
    // -------------------------------------------------------------------------

    public function doctors(Request $request): JsonResponse
    {
        $request->validate([
            'specialty_id' => ['required_without:doctor_id', 'exists:specialties,id'],
            'doctor_id'    => ['required_without:specialty_id', 'exists:doctors,id'],
        ]);

        // Busca por ID específico
        if ($request->filled('doctor_id')) {
            $doctor = Doctor::with(['user', 'specialty', 'plans', 'workTimes', 'information'])
                ->where('show_in_bot', true)
                ->findOrFail($request->query('doctor_id'));

            return response()->json([
                'id'        => $doctor->id,
                'name'      => $doctor->user?->name,
                'specialty' => $doctor->specialty ? [
                    'id'   => $doctor->specialty->id,
                    'name' => $doctor->specialty->name,
                ] : null,
                'plans' => $doctor->plans->map(fn($p) => [
                    'id'                 => $p->id,
                    'name'               => $p->name,
                    'consultation_value' => $p->pivot->consultation_value ?? null,
                ]),
                'work_times' => $doctor->workTimes->map(fn($w) => [
                    'day_of_week' => $w->day_of_week,
                    'period'      => $w->period,
                    'start_time'  => $w->start_time,
                    'end_time'    => $w->end_time,
                ]),
                'information' => $doctor->information
                    ->where('active', true)
                    ->map(fn($i) => [
                        'id'          => $i->id,
                        'key'         => $i->key,
                        'information' => $i->information,
                    ]),
                'schedule_config' => $doctor->scheduleConfig ? [
                    'id' => $doctor->scheduleConfig->id,
                    'config' => $doctor->scheduleConfig->config,
                ] : null,
            ]);
        }

        // Listagem por especialidade
        $doctors = Doctor::with('user')
            ->where('specialty_id', $request->query('specialty_id'))
            ->where('show_in_bot', true)
            ->orderBy('id')
            ->get();

        return response()->json($doctors->map(fn(Doctor $d) => [
            'id'   => $d->id,
            'name' => $d->user?->name,
        ]));
    }

    // -------------------------------------------------------------------------
    // Horários disponíveis
    // -------------------------------------------------------------------------

    public function availableSlots(Request $request, Doctor $doctor): JsonResponse
    {
        $request->validate([
            'date'     => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'duration' => ['nullable', 'integer', 'min:15', 'max:120'],
        ]);

        $date         = Carbon::createFromFormat('Y-m-d', $request->query('date'));
        $slotMinutes  = (int) $request->query('duration', 30);
        $dayOfWeek    = (int) $date->dayOfWeek; // 0=Dom … 6=Sáb

        $workTimes = $doctor->workTimes()
            ->where('day_of_week', $dayOfWeek)
            ->get();

        if ($workTimes->isEmpty()) {
            return response()->json([]);
        }

        // Eventos já existentes nesse dia (appointments + bloqueios)
        $busyTimes = $doctor->events()
            ->whereDate('date', $date->toDateString())
            ->get(['time', 'duration'])
            ->map(fn(Event $e) => [
                'start' => Carbon::parse($e->getRawOriginal('time')),
                'end'   => Carbon::parse($e->getRawOriginal('time'))
                    ->addMinutes($this->durationToMinutes($e->getRawOriginal('duration'))),
            ]);

        $slots = [];

        foreach ($workTimes as $wt) {
            $cursor = Carbon::createFromFormat('H:i:s', $wt->start_time);
            $end    = Carbon::createFromFormat('H:i:s', $wt->end_time);

            while ($cursor->copy()->addMinutes($slotMinutes)->lte($end)) {
                $slotStart = $cursor->copy();
                $slotEnd   = $cursor->copy()->addMinutes($slotMinutes);

                $busy = $busyTimes->first(function ($bt) use ($slotStart, $slotEnd) {
                    return $slotStart->lt($bt['end']) && $slotEnd->gt($bt['start']);
                });

                if (! $busy) {
                    $slots[] = $slotStart->format('H:i');
                }

                $cursor->addMinutes($slotMinutes);
            }
        }

        return response()->json($slots);
    }

    // -------------------------------------------------------------------------
    // Pacientes
    // -------------------------------------------------------------------------

    public function findPatient(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $raw    = preg_replace('/\D+/', '', $request->query('phone', ''));
        // WhatsApp envia com DDI 55 (ex: 5511999990000); remove para bater com o banco
        $digits = preg_match('/^55\d{10,11}$/', $raw) ? substr($raw, 2) : $raw;

        $patient = Patient::whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '') LIKE ?",
            ['%' . $digits . '%']
        )->first(['id', 'name', 'phone', 'document', 'birthday', 'gender']);

        if (! $patient) {
            return response()->json(['found' => false]);
        }

        return response()->json(['found' => true, 'patient' => $patient]);
    }

    public function createPatient(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name'  => ['required', 'string', 'min:3', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
        ]);

        $patient = Patient::create($input);

        return response()->json($patient->only(['id', 'name', 'phone']), 201);
    }

    // -------------------------------------------------------------------------
    // Agendamentos do paciente
    // -------------------------------------------------------------------------

    public function patientAppointments(Patient $patient): JsonResponse
    {
        $appointments = $patient->appointments()
            ->with(['event.doctor.user:id,name,type,profile_id', 'event'])
            ->whereHas('event', fn($q) => $q->whereDate('date', '>=', today()))
            ->orderBy(
                Event::select('date')->whereColumn('event_id', 'appointments.id')->whereColumn('type', 'App\\Models\\Appointment')->limit(1)
            )
            ->get();

        return response()->json($appointments->map(fn(Appointment $a) => [
            'id'          => $a->id,
            'date'        => $a->event?->date,
            'time'        => $a->event ? Carbon::parse($a->event->getRawOriginal('time'))->format('H:i') : null,
            'duration'    => $a->event ? $a->event->getRawOriginal('duration') : null,
            'doctor_name' => $a->event?->doctor?->user?->name,
            'status'      => $a->status,
            'type'        => $a->type,
        ]));
    }

    // -------------------------------------------------------------------------
    // Criar agendamento
    // -------------------------------------------------------------------------

    public function createAppointment(Request $request, Doctor $doctor): JsonResponse
    {
        $input = $request->validate([
            'date'       => ['required', 'string', 'date_format:Y-m-d'],
            'time'       => ['required', 'string', 'date_format:H:i'],
            'duration'   => ['nullable', 'string', 'date_format:H:i'],
            'patient_id' => ['required', 'exists:patients,id'],
            'plan_id'    => ['required', 'exists:plans,id'],
            'type'       => ['required', 'string', 'in:first,default,return,free'],
            'comment'    => ['nullable', 'string', 'max:255'],
            'user_id'   => ['required', 'exists:users,id'],
        ]);

        $input['duration'] = $input['duration'] ?? '00:30';

        $appointment = new Appointment($input);
        $appointment->save();

        $event = new Event(array_merge($input, ['doctor_id' => $doctor->id]));
        $appointment->event()->save($event);

        AppointmentLogController::log($appointment->id, 'create', $input['user_id']);
        ScheduleUpdated::dispatch($doctor);

        return response()->json([
            'id'       => $appointment->id,
            'date'     => $event->date,
            'time'     => Carbon::parse($event->getRawOriginal('time'))->format('H:i'),
            'duration' => $event->getRawOriginal('duration'),
            'patient'  => $appointment->patient->only(['id', 'name']),
            'doctor'   => ['id' => $doctor->id, 'name' => $doctor->user?->name],
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Atualizar status (confirmar / cancelar)
    // -------------------------------------------------------------------------

    public function updateAppointmentStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $input = $request->validate([
            'status' => ['required'],
        ]);

        $appointment->update($input);
        ScheduleUpdated::dispatch($appointment->event->doctor);

        return response()->json(['id' => $appointment->id, 'status' => $appointment->status]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function durationToMinutes(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return ((int) $h) * 60 + (int) $m;
    }
}
