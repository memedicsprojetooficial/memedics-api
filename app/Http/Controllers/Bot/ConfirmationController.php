<?php

namespace App\Http\Controllers\Bot;

use App\Exceptions\ConfirmationNotSent;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\DoctorScheduleConfig;
use App\Models\Event;
use App\Services\AppointmentConfirmationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Envio automático de confirmação de consulta. O n8n roda uma vez por dia: busca as
 * devidas (`due`) e chama `send` uma a uma, espaçando os envios para não arriscar
 * bloqueio do número no WhatsApp.
 */
class ConfirmationController extends Controller
{
    private const TIMEZONE = 'America/Sao_Paulo';

    // Só "Agendado": quem já recebeu (Aguardando Confirmação), confirmou ou cancelou fica de fora.
    private const SCHEDULED_STATUS = 1;

    public function __construct(private readonly AppointmentConfirmationService $confirmation)
    {
    }

    /**
     * Consultas de médicos com envio automático ligado (`confirmation_days_before`) que caem
     * entre amanhã e hoje + N dias. A janela (e não "exatamente daqui a N dias") faz quem
     * agendou depois da rodada ou ficou de fora por falha ser enviado na rodada seguinte.
     */
    public function due(): JsonResponse
    {
        $daysByDoctor = DoctorScheduleConfig::whereNotNull('confirmation_days_before')
            ->where('confirmation_days_before', '>', 0)
            ->pluck('confirmation_days_before', 'doctor_id');

        if ($daysByDoctor->isEmpty()) {
            return response()->json([]);
        }

        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        $events = Event::query()
            ->whereIn('doctor_id', $daysByDoctor->keys())
            ->whereDate('date', '>', $today->toDateString())
            ->whereDate('date', '<=', $today->copy()->addDays($daysByDoctor->max())->toDateString())
            ->whereHasMorph('event', [Appointment::class], fn ($q) => $q->where('status', self::SCHEDULED_STATUS))
            ->orderBy('date')
            ->orderBy('time')
            ->get();

        $due = $events
            ->filter(fn (Event $e) => $this->isDue($e->getRawOriginal('date'), $today, (int) $daysByDoctor[$e->doctor_id]))
            ->map(fn (Event $e) => [
                'id'             => $e->id, // id do evento — é o que as rotas de agendamento esperam
                'appointment_id' => $e->event_id,
                'doctor_id'      => $e->doctor_id,
                'date'           => substr($e->getRawOriginal('date'), 0, 10),
                'time'           => substr($e->getRawOriginal('time'), 0, 5),
            ])
            ->values();

        return response()->json($due);
    }

    public function send(Appointment $appointment): JsonResponse
    {
        // A lista pode ter sido gerada há alguns minutos: confere de novo antes de enviar.
        $date = Carbon::parse($appointment->event->getRawOriginal('date'), self::TIMEZONE)->startOfDay();

        if ($appointment->status !== self::SCHEDULED_STATUS || !$date->isAfter(Carbon::now(self::TIMEZONE)->startOfDay())) {
            return response()->json(['sent' => false, 'message' => 'Agendamento não está mais pendente de confirmação.'], 409);
        }

        try {
            $this->confirmation->send($appointment);
        } catch (ConfirmationNotSent $e) {
            return response()->json(['sent' => false, 'message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['sent' => false, 'message' => 'Erro interno ao enviar a confirmação pelo WhatsApp.'], 500);
        }

        return response()->json(['sent' => true, 'message' => 'Mensagem de confirmação enviada com sucesso.']);
    }

    private function isDue(string $date, Carbon $today, int $daysBefore): bool
    {
        $day = Carbon::parse(substr($date, 0, 10), self::TIMEZONE)->startOfDay();

        return $day->isAfter($today) && $day->lte($today->copy()->addDays($daysBefore));
    }
}
