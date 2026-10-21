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
use App\Models\UnitAddress;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotController extends Controller
{
    // O app roda em UTC; "hoje" e o limite de agendamento valem no horário de Brasília.
    private const TIMEZONE = 'America/Sao_Paulo';

    // O bot só consulta e agenda até este número de meses à frente de hoje.
    private const MAX_MONTHS_AHEAD = 3;

    // Tempo de atendimento usado quando o médico ainda não tem configuração (padrão da agenda web).
    private const DEFAULT_SLOT_MINUTES = 20;

    private const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

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

        // `option` é a posição exibida ao paciente; `id` é o que as demais rotas esperam.
        return response()->json($specialties->values()->map(fn (Specialty $s, int $i) => [
            'option' => $i + 1,
            'id'     => $s->id,
            'name'   => $s->name,
        ]));
    }

    // -------------------------------------------------------------------------
    // Médicos
    // -------------------------------------------------------------------------

    /**
     * Unidade dona de uma sessão da WAHA e se o bot dela está pausado. O n8n consulta
     * isto a cada mensagem recebida.
     */
    public function unitBySession(string $session): JsonResponse
    {
        $unit = UnitAddress::where('waha_session_name', $session)->first();

        if (!$unit) {
            return response()->json(['message' => 'Nenhuma unidade vinculada a esta sessão.'], 404);
        }

        return response()->json([
            'unit_address_id'  => $unit->id,
            'bot_paused'       => $unit->isBotPaused(),
            'bot_paused_until' => $unit->isBotPaused() ? $unit->bot_paused_until : null,
        ]);
    }

    public function doctors(Request $request): JsonResponse
    {
        $request->validate([
            'specialty_id'    => ['required_without:doctor_id', 'exists:specialties,id'],
            'doctor_id'       => ['required_without:specialty_id', 'exists:doctors,id'],
            'unit_address_id' => ['nullable', 'exists:unit_addresses,id'],
        ]);

        // Busca por ID específico (restrita à unidade informada, se houver)
        if ($request->filled('doctor_id')) {
            $doctor = Doctor::with(['user', 'specialty', 'plans', 'workTimes', 'information'])
                ->where('show_in_bot', true)
                ->when($request->filled('unit_address_id'), fn ($q) => $q->where('unit_addresses_id', $request->query('unit_address_id')))
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
                'slot_minutes' => $this->slotMinutesFor($doctor),
            ]);
        }

        // Listagem por especialidade (opcionalmente filtrada por unidade)
        $doctors = Doctor::with(['user', 'specialty'])
            ->where('specialty_id', $request->query('specialty_id'))
            ->where('show_in_bot', true)
            ->when($request->filled('unit_address_id'), fn ($q) => $q->where('unit_addresses_id', $request->query('unit_address_id')))
            ->orderBy('id')
            ->get();

        // `specialty` permite ao bot conferir que listou a especialidade escolhida.
        return response()->json($doctors->values()->map(fn (Doctor $d, int $i) => [
            'option'    => $i + 1,
            'id'        => $d->id,
            'name'      => $d->user?->name,
            'specialty' => $d->specialty?->name,
        ]));
    }

    // -------------------------------------------------------------------------
    // Horários disponíveis
    // -------------------------------------------------------------------------

    public function availableSlots(Request $request, Doctor $doctor): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $this->today()->toDateString(), 'before_or_equal:' . $this->lastBookableDate()->toDateString()],
        ], $this->dateRangeMessages());

        $date = Carbon::createFromFormat('Y-m-d', $request->query('date'), self::TIMEZONE)->startOfDay();

        return response()->json($this->slotsForDate($doctor, $date));
    }

    /**
     * Horários livres de um dia, seguindo o tempo de atendimento do médico.
     *
     * @return string[]
     */
    private function slotsForDate(Doctor $doctor, Carbon $date): array
    {
        $now = Carbon::now(self::TIMEZONE);

        $workTimes = $doctor->workTimes()
            ->where('day_of_week', $date->dayOfWeek) // 0=Dom … 6=Sáb
            ->get();

        // Eventos já existentes nesse dia (appointments + bloqueios)
        $busy = $doctor->events()
            ->whereDate('date', $date->toDateString())
            ->get(['time', 'duration'])
            ->map(fn (Event $e) => $this->busyInterval($e))
            ->all();

        return $this->computeSlots($workTimes, $busy, $this->slotMinutesFor($doctor), $date->isSameDay($now) ? $now : null);
    }

    /**
     * Tempo de atendimento do médico em minutos (doctor_schedule_configs.slot_duration).
     */
    private function slotMinutesFor(Doctor $doctor): int
    {
        $duration = $doctor->scheduleConfig?->slot_duration;

        return $duration ? $this->durationToMinutes($duration) : self::DEFAULT_SLOT_MINUTES;
    }

    /**
     * Próximos dias com horário livre do médico (a partir de hoje), dentro do limite
     * de MAX_MONTHS_AHEAD meses. Se `available` vier false, não há vaga no período e o
     * paciente deve ser transferido para o atendimento humano.
     */
    public function nextAvailability(Request $request, Doctor $doctor): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $slotMinutes = $this->slotMinutesFor($doctor);
        $now         = Carbon::now(self::TIMEZONE);
        $first = $now->copy()->startOfDay();
        $last  = $this->lastBookableDate();

        $workByDay = $doctor->workTimes()->get()->groupBy('day_of_week');

        $busyByDate = $doctor->events()
            ->whereBetween('date', [$first->toDateString(), $last->toDateString()])
            ->get(['date', 'time', 'duration'])
            ->groupBy(fn (Event $e) => substr($e->getRawOriginal('date'), 0, 10))
            ->map(fn ($events) => $events->map(fn (Event $e) => $this->busyInterval($e))->all());

        $days = $this->findOpenDays(
            $workByDay,
            $busyByDate,
            $first,
            $last,
            $now,
            (int) $request->query('days', 3),
            $slotMinutes,
        );

        return response()->json([
            'available'      => count($days) > 0,
            'slot_minutes'   => $slotMinutes,
            'searched_until' => $last->toDateString(),
            'days'           => $days,
        ]);
    }

    private function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    private function lastBookableDate(): Carbon
    {
        return $this->today()->addMonthsNoOverflow(self::MAX_MONTHS_AHEAD);
    }

    private function dateRangeMessages(): array
    {
        return [
            'date.after_or_equal'  => 'A data não pode ser anterior a hoje.',
            'date.before_or_equal' => 'Só é possível consultar e agendar até :date (' . self::MAX_MONTHS_AHEAD . ' meses a partir de hoje).',
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function busyInterval(Event $e): array
    {
        $start = Carbon::parse($e->getRawOriginal('time'));

        return [
            'start' => $start,
            'end'   => $start->copy()->addMinutes($this->durationToMinutes($e->getRawOriginal('duration'))),
        ];
    }

    /**
     * Horários livres de um dia (lógica pura, sem banco).
     *
     * @param iterable $workTimes   itens com start_time/end_time ('H:i:s') do dia da semana
     * @param array    $busy        intervalos ocupados, de busyInterval()
     * @param Carbon|null $notBefore quando for hoje, descarta horários que já começaram
     * @return string[] horários 'H:i'
     */
    private function computeSlots(iterable $workTimes, array $busy, int $slotMinutes, ?Carbon $notBefore = null): array
    {
        $minStart = $notBefore ? Carbon::createFromFormat('H:i:s', $notBefore->format('H:i:s')) : null;
        $slots    = [];

        foreach ($workTimes as $wt) {
            $cursor = Carbon::createFromFormat('H:i:s', $wt->start_time);
            $end    = Carbon::createFromFormat('H:i:s', $wt->end_time);

            while ($cursor->copy()->addMinutes($slotMinutes)->lte($end)) {
                $slotStart = $cursor->copy();
                $slotEnd   = $cursor->copy()->addMinutes($slotMinutes);

                $isBusy = collect($busy)->contains(
                    fn ($b) => $slotStart->lt($b['end']) && $slotEnd->gt($b['start'])
                );

                if (! $isBusy && (! $minStart || $slotStart->gt($minStart))) {
                    $slots[] = $slotStart->format('H:i');
                }

                $cursor->addMinutes($slotMinutes);
            }
        }

        sort($slots);

        return array_values(array_unique($slots));
    }

    /**
     * Percorre os dias de $first a $last e devolve os primeiros $wanted com vaga.
     *
     * @param \Illuminate\Support\Collection $workByDay  day_of_week => horários de atendimento
     * @param \Illuminate\Support\Collection $busyByDate 'Y-m-d' => intervalos ocupados
     * @return array<int, array{date: string, weekday: string, slots: string[]}>
     */
    private function findOpenDays($workByDay, $busyByDate, Carbon $first, Carbon $last, Carbon $now, int $wanted, int $slotMinutes): array
    {
        $days = [];

        for ($day = $first->copy(); $day->lte($last) && count($days) < $wanted; $day->addDay()) {
            $workTimes = $workByDay->get($day->dayOfWeek);

            if (! $workTimes) {
                continue;
            }

            $slots = $this->computeSlots(
                $workTimes,
                $busyByDate->get($day->toDateString(), []),
                $slotMinutes,
                $day->isSameDay($now) ? $now : null,
            );

            if ($slots) {
                $days[] = [
                    'date'    => $day->toDateString(),
                    'weekday' => self::WEEKDAYS[$day->dayOfWeek],
                    'slots'   => $slots,
                ];
            }
        }

        return $days;
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
            'date'       => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:' . $this->today()->toDateString(), 'before_or_equal:' . $this->lastBookableDate()->toDateString()],
            'time'       => ['required', 'string', 'date_format:H:i'],
            'duration'   => ['nullable', 'string', 'date_format:H:i'],
            'patient_id' => ['required', 'exists:patients,id'],
            'plan_id'    => ['required', 'exists:plans,id'],
            'type'       => ['required', 'string', 'in:first,default,return,free,online,medical-fitting,procedure,exam'],
            'comment'    => ['nullable', 'string', 'max:255'],
            'user_id'   => ['required', 'exists:users,id'],
        ], $this->dateRangeMessages());

        // A duração segue o tempo de atendimento do médico (o que o bot enviar é ignorado) e o
        // horário precisa ser um dos livres da agenda dele.
        $slotMinutes = $this->slotMinutesFor($doctor);
        $date        = Carbon::createFromFormat('Y-m-d', $input['date'], self::TIMEZONE)->startOfDay();

        if (! in_array($input['time'], $this->slotsForDate($doctor, $date), true)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => ['time' => ['Este horário não está livre na agenda do médico. Consulte os horários disponíveis novamente.']],
            ], 422);
        }

        $input['duration'] = sprintf('%02d:%02d', intdiv($slotMinutes, 60), $slotMinutes % 60);

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
