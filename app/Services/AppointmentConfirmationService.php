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
 * consulta. Usado pelo botão "Enviar Confirmação", pelo envio automático e pela cobrança
 * ("Cobrar Confirmação"). Cada tentativa, com o resultado, fica em `confirmation_dispatches`.
 */
class AppointmentConfirmationService
{
    /**
     * Status "Aguardando Confirmação" — setado automaticamente ao enviar o link.
     */
    public const AWAITING_CONFIRMATION_STATUS = 13;

    private const TIMEZONE = 'America/Sao_Paulo';

    public function __construct(private readonly WahaService $waha)
    {
    }

    /**
     * @param string $origin ConfirmationDispatch::ORIGIN_MANUAL ou ORIGIN_AUTOMATIC
     * @throws ConfirmationNotSent
     */
    public function send(Appointment $appointment, string $origin = ConfirmationDispatch::ORIGIN_MANUAL): void
    {
        $this->attempt($appointment, $origin, ConfirmationDispatch::KIND_CONFIRMATION,
            fn (string $url) => $this->buildMessage($appointment, $url));

        $appointment->update(['status' => self::AWAITING_CONFIRMATION_STATUS]);
        ScheduleUpdated::dispatch($appointment->event->doctor);
    }

    /**
     * Cobrança: lembra o paciente que ainda não respondeu, na véspera ou no dia da consulta.
     * Só manual, pode repetir e não muda o status.
     *
     * @throws ConfirmationNotSent
     */
    public function remind(Appointment $appointment): void
    {
        if ((int) $appointment->status !== self::AWAITING_CONFIRMATION_STATUS) {
            throw new ConfirmationNotSent('O paciente já respondeu ou a consulta não está aguardando confirmação.', 409);
        }

        $day = $this->daysUntil($appointment);

        if ($day !== 0 && $day !== 1) {
            throw new ConfirmationNotSent('A cobrança só pode ser enviada na véspera ou no dia da consulta.', 422);
        }

        $this->attempt($appointment, ConfirmationDispatch::ORIGIN_MANUAL, ConfirmationDispatch::KIND_REMINDER,
            fn (string $url) => $this->buildReminder($appointment, $url, $day === 0 ? 'hoje' : 'amanhã'));
    }

    /**
     * Envia e grava a tentativa no histórico, com sucesso ou falha.
     *
     * @param callable(string): string $buildMessage recebe o link e devolve o texto
     * @throws ConfirmationNotSent
     */
    private function attempt(Appointment $appointment, string $origin, string $kind, callable $buildMessage): void
    {
        try {
            $this->deliver($appointment, $buildMessage);
        } catch (ConfirmationNotSent $e) {
            $this->record($appointment, $origin, $kind, false, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->record($appointment, $origin, $kind, false, 'Erro interno ao enviar a confirmação.');
            throw $e;
        }

        // Registra antes de quem chamou mudar o status: a mensagem já saiu, mesmo que o resto falhe.
        $this->record($appointment, $origin, $kind, true);
    }

    /**
     * Dias da data de hoje (Brasília) até a data da consulta: 0 = hoje, 1 = amanhã.
     */
    private function daysUntil(Appointment $appointment): int
    {
        $date = substr((string) ($appointment->event->getAttributes()['date'] ?? ''), 0, 10);
        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        return (int) $today->diffInDays(Carbon::parse($date, self::TIMEZONE)->startOfDay(), false);
    }

    /**
     * @param callable(string): string $buildMessage
     * @throws ConfirmationNotSent
     */
    private function deliver(Appointment $appointment, callable $buildMessage): void
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

            $this->waha->sendLinkMessage($unit->waha_session_name, $number, $buildMessage($url));
        } catch (RuntimeException $e) {
            // 422, não 502: o nginx de produção troca respostas 5xx pela página HTML de erro
            // e o atendente perderia a mensagem.
            throw new ConfirmationNotSent("Não foi possível enviar pelo WhatsApp da unidade: {$e->getMessage()}", 422);
        }
    }

    /**
     * Grava a tentativa no histórico. Uma falha aqui nunca impede nem desfaz o envio.
     */
    private function record(Appointment $appointment, string $origin, string $kind, bool $sent, ?string $reason = null): void
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
                'kind'            => $kind,
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
        $date = Carbon::parse($appointment->event->date)->format('d/m/Y');
        $time = $appointment->event->time->format('H:i');
        $doctorName = $appointment->event->doctor->user->name ?? '';

        return "Olá, {$this->firstName($appointment)}! Segue abaixo os detalhes da sua consulta agendada:\n\n"
            . "Data: {$date}\n"
            . "Horário: {$time}\n"
            . "Médico(a): {$doctorName}\n"
            . $this->unitLines($appointment)
            . "\nPara confirmar ou cancelar, acesse o link abaixo:\n{$url}";
    }

    /**
     * @param string $when "hoje" ou "amanhã"
     */
    private function buildReminder(Appointment $appointment, string $url, string $when): string
    {
        $date = Carbon::parse($appointment->event->date)->format('d/m');
        $time = $appointment->event->time->format('H:i');
        $doctorName = $appointment->event->doctor->user->name ?? '';
        $withDoctor = $doctorName ? ", com {$doctorName}" : '';

        return "Olá, {$this->firstName($appointment)}! Sua consulta é {$when}, {$date} às {$time}{$withDoctor}.\n"
            . $this->unitLines($appointment)
            . "\nAinda não recebemos sua confirmação. Por favor, confirme ou cancele pelo link abaixo:\n{$url}";
    }

    private function firstName(Appointment $appointment): string
    {
        return explode(' ', trim($appointment->patient->name))[0];
    }

    /**
     * Local, endereço e aviso da unidade, como aparecem nas duas mensagens.
     */
    private function unitLines(Appointment $appointment): string
    {
        $unit = $appointment->event->doctor->unitAddress;
        $unitLine = $unit?->unit_name ? "Local: {$unit->unit_name}\n" : '';
        $addressLine = $unit?->confirmation_address ? "📍End: {$unit->confirmation_address}\n" : '';
        $noteLine = $unit?->confirmation_note ? "\n{$unit->confirmation_note}\n" : '';

        return $unitLine . $addressLine . $noteLine;
    }
}
