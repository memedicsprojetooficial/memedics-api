<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ConfirmationDispatch;
use App\Models\ConfirmationRun;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Histórico dos envios da confirmação de consulta (tela "Envios de confirmação" e janela da
 * consulta na agenda).
 */
class ConfirmationDispatchController extends Controller
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'result' => ['nullable', 'in:sent,failed'],
            'origin' => ['nullable', 'in:' . ConfirmationDispatch::ORIGIN_MANUAL . ',' . ConfirmationDispatch::ORIGIN_AUTOMATIC],
            'date'   => ['nullable', 'date_format:Y-m-d'],
            'kind'   => ['nullable', 'in:' . ConfirmationDispatch::KIND_CONFIRMATION . ',' . ConfirmationDispatch::KIND_REMINDER],
            'unit_address_id' => ['nullable', 'integer'],
        ]);

        $query = ConfirmationDispatch::query()
            ->with(['patient', 'doctor.user', 'unitAddress', 'user', 'event'])
            ->when($filters['result'] ?? null, fn ($q, $result) => $q->where('sent', $result === 'sent'))
            ->when($filters['origin'] ?? null, fn ($q, $origin) => $q->where('origin', $origin))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['unit_address_id'] ?? null, fn ($q, $unitId) => $q->where('unit_address_id', $unitId))
            ->when($filters['date'] ?? null, function ($q, $date) {
                // O dia é o de Brasília; created_at fica em UTC.
                $start = Carbon::parse($date, self::TIMEZONE)->startOfDay()->utc();
                $q->whereBetween('created_at', [$start, $start->copy()->addDay()->subSecond()]);
            })
            ->orderByDesc('id');

        return JsonResource::collection(
            $query->cursorPaginate(25)->withQueryString()->through(fn (ConfirmationDispatch $d) => $this->present($d))
        );
    }

    /**
     * Última rodada do envio automático e quantos envios ela fez, para mostrar que rodou.
     */
    public function summary(): JsonResponse
    {
        $run = ConfirmationRun::latest('id')->first();

        if (!$run) {
            return response()->json(['last_run' => null]);
        }

        // Os envios de uma rodada são os automáticos feitos depois dela começar (até a próxima, se houver).
        $dispatches = ConfirmationDispatch::where('origin', ConfirmationDispatch::ORIGIN_AUTOMATIC)
            ->where('kind', ConfirmationDispatch::KIND_CONFIRMATION)
            ->where('created_at', '>=', $run->created_at);

        return response()->json([
            'last_run' => [
                'started_at' => $run->created_at,
                'due_count'  => $run->due_count,
                'sent'       => (clone $dispatches)->where('sent', true)->count(),
                'failed'     => (clone $dispatches)->where('sent', false)->count(),
            ],
        ]);
    }

    /**
     * Envios de uma consulta, do mais recente para o mais antigo.
     */
    public function forAppointment(Appointment $appointment): JsonResponse
    {
        $dispatches = ConfirmationDispatch::with(['user', 'event'])
            ->where('appointment_id', $appointment->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (ConfirmationDispatch $d) => $this->present($d));

        return response()->json(['data' => $dispatches]);
    }

    private function present(ConfirmationDispatch $d): array
    {
        return [
            'id'             => $d->id,
            'event_id'       => $d->event_id,
            'patient_id'     => $d->patient_id,
            // Médico e data atuais do agendamento, para abrir na agenda; nulos se ele foi excluído.
            'appointment_doctor_id' => $d->event?->doctor_id,
            'appointment_date'      => $d->event ? substr((string) $d->event->getAttributes()['date'], 0, 10) : null,
            'patient_name'   => $d->patient?->name,
            'doctor_name'    => $d->doctor?->user?->name,
            'unit_name'      => $d->unitAddress?->unit_name,
            'scheduled_for'  => $d->scheduled_for?->format('Y-m-d H:i'),
            'origin'         => $d->origin,
            'kind'           => $d->kind ?? ConfirmationDispatch::KIND_CONFIRMATION,
            'sent'           => $d->sent,
            'reason'         => $d->reason,
            'user_name'      => $d->user?->name,
            'created_at'     => $d->created_at,
        ];
    }
}
