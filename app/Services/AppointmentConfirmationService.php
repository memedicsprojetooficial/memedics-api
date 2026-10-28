<?php

namespace App\Services;

use App\Events\ScheduleUpdated;
use App\Exceptions\ConfirmationNotSent;
use App\Models\Appointment;
use App\Models\ConfirmationDispatch;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Envia ao paciente, pelo WhatsApp da unidade, o link público para confirmar ou cancelar a
 * consulta. Usado pelo botão "Enviar Confirmação" e pelo envio automático. Cada tentativa,
 * com o resultado, fica em `confirmation_dispatches`.
 */
class AppointmentConfirmationService
{
    /**
     * Status "Aguardando Confirmação" — setado automaticamente ao enviar o link.
     */
    public const AWAITING_CONFIRMATION_STATUS = 13;

    public function __construct(private readonly WahaService $waha)
    {
    }

    /**
     * @param string $origin ConfirmationDispatch::ORIGIN_MANUAL ou ORIGIN_AUTOMATIC
     * @throws ConfirmationNotSent
     */
    public function send(Appointment $appointment, string $origin = ConfirmationDispatch::ORIGIN_MANUAL): void
    {
        try {
            $this->deliver($appointment);
        } catch (ConfirmationNotSent $e) {
            $this->record($appointment, $origin, false, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->record($appointment, $origin, false, 'Erro interno ao enviar a confirmação.');
            throw $e;
        }

        // Registra antes de mudar o status: a mensagem já saiu, mesmo que o resto falhe.
        $this->record($appointment, $origin, true);

        $appointment->update(['status' => self::AWAITING_CONFIRMATION_STATUS]);
        ScheduleUpdated::dispatch($appointment->event->doctor);
    }

    /**
     * @throws ConfirmationNotSent
     */
    private function deliver(Appointment $appointment): void
    {
        if (!$appointment->public_token) {
            $appointment->update(['public_token' => Str::random(40)]);
        }

        $unit = $appointment->event->doctor->unitAddress;

        if (!$unit || !$unit->waha_session_name) {
            throw new ConfirmationNotSent('Esta unidade não possui WhatsApp conectado. Configure em Configurações → Unidades.', 404);
        }

        $number = PhoneNumber::forWhatsapp($appointment->patient->phone ?? null);

        if (!$number) {
            throw new ConfirmationNotSent('Paciente sem telefone cadastrado.', 422);
        }

        if (!PhoneNumber::isComplete($number)) {
            throw new ConfirmationNotSent('Telefone do paciente incompleto. Corrija o cadastro informando DDD e número.', 422);
        }

        try {
            $status = $this->waha->getStatus($unit->waha_session_name);

            if (($status['status'] ?? null) !== 'WORKING' || empty($status['me'])) {
                throw new ConfirmationNotSent('WhatsApp da unidade está desconectado. Reconecte em Configurações → Unidades.', 422);
            }

            $baseUrl = rtrim(config('app.frontend_url'), '/');
            $url = "{$baseUrl}/confirmar-consulta/{$appointment->public_token}";

            $this->waha->sendLinkMessage($unit->waha_session_name, $number, $this->buildMessage($appointment, $url));
        } catch (RuntimeException $e) {
            // 422, não 502: o nginx de produção troca respostas 5xx pela página HTML de erro
            // e o atendente perderia a mensagem.
            throw new ConfirmationNotSent("Não foi possível enviar pelo WhatsApp da unidade: {$e->getMessage()}", 422);
        }
    }

    /**
     * Grava a tentativa no histórico. Uma falha aqui nunca impede nem desfaz o envio.
     */
    private function record(Appointment $appointment, string $origin, bool $sent, ?string $reason = null): void
    {
        try {
            $event = $appointment->event;
            $raw = $event?->getAttributes() ?? [];
            $date = isset($raw['date']) ? substr((string) $raw['date'], 0, 10) : null;
            $time = isset($raw['time']) ? substr((string) $raw['time'], 0, 8) : null;

            ConfirmationDispatch::create([
                'appointment_id'  => $appointment->id,
                'event_id'        => $event?->id,
                'patient_id'      => $appointment->patient_id,
                'doctor_id'       => $event?->doctor_id,
                'unit_address_id' => $event?->doctor?->unit_addresses_id,
                'scheduled_for'   => $date ? trim("{$date} {$time}") : null,
                'origin'          => $origin,
                'sent'            => $sent,
                'reason'          => $reason ? Str::limit($reason, 497) : null,
                'user_id'         => auth()->id(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function buildMessage(Appointment $appointment, string $url): string
    {
        $firstName = explode(' ', trim($appointment->patient->name))[0];
        $date = Carbon::parse($appointment->event->date)->format('d/m/Y');
        $time = $appointment->event->time->format('H:i');
        $doctorName = $appointment->event->doctor->user->name ?? '';
        $unit = $appointment->event->doctor->unitAddress;
        $unitLine = $unit?->unit_name ? "Local: {$unit->unit_name}\n" : '';
        $addressLine = $unit?->confirmation_address ? "📍End: {$unit->confirmation_address}\n" : '';
        $noteLine = $unit?->confirmation_note ? "\n{$unit->confirmation_note}\n" : '';

        return "Olá, {$firstName}! Segue abaixo os detalhes da sua consulta agendada:\n\n"
            . "Data: {$date}\n"
            . "Horário: {$time}\n"
            . "Médico(a): {$doctorName}\n"
            . $unitLine
            . $addressLine
            . $noteLine
            . "\nPara confirmar ou cancelar, acesse o link abaixo:\n{$url}";
    }
}
