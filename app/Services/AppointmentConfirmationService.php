<?php

namespace App\Services;

use App\Events\ScheduleUpdated;
use App\Exceptions\ConfirmationNotSent;
use App\Models\Appointment;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Envia ao paciente, pelo WhatsApp da unidade, o link público para confirmar ou cancelar a
 * consulta. Usado pelo botão "Enviar Confirmação" e pelo envio automático.
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
     * @throws ConfirmationNotSent
     */
    public function send(Appointment $appointment): void
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

        try {
            $status = $this->waha->getStatus($unit->waha_session_name);

            if (($status['status'] ?? null) !== 'WORKING' || empty($status['me'])) {
                throw new ConfirmationNotSent('WhatsApp da unidade está desconectado. Reconecte em Configurações → Unidades.', 422);
            }

            $baseUrl = rtrim(config('app.frontend_url'), '/');
            $url = "{$baseUrl}/confirmar-consulta/{$appointment->public_token}";

            $this->waha->sendLinkMessage($unit->waha_session_name, $number, $this->buildMessage($appointment, $url));
        } catch (RuntimeException $e) {
            throw new ConfirmationNotSent($e->getMessage(), 502);
        }

        $appointment->update(['status' => self::AWAITING_CONFIRMATION_STATUS]);
        ScheduleUpdated::dispatch($appointment->event->doctor);
    }

    private function buildMessage(Appointment $appointment, string $url): string
    {
        $firstName = explode(' ', trim($appointment->patient->name))[0];
        $date = Carbon::parse($appointment->event->date)->format('d/m/Y');
        $time = $appointment->event->time->format('H:i');
        $doctorName = $appointment->event->doctor->user->name ?? '';
        $unitName = $appointment->event->doctor->unitAddress->unit_name ?? '';
        $unitLine = $unitName ? "Local: {$unitName}\n" : '';

        return "Olá, {$firstName}! Segue abaixo os detalhes da sua consulta agendada:\n\n"
            . "Data: {$date}\n"
            . "Horário: {$time}\n"
            . "Médico(a): {$doctorName}\n"
            . $unitLine
            . "\nPara confirmar ou cancelar, acesse o link abaixo:\n{$url}";
    }
}
