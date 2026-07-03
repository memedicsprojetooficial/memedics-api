<?php

namespace App\Http\Controllers;

use App\Events\ScheduleUpdated;
use App\Models\Appointment;
use App\Services\EvolutionGoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AppointmentPublicLinkController extends Controller
{
    /**
     * Status "Aguardando Confirmação" — setado automaticamente ao enviar o link.
     */
    private const AWAITING_CONFIRMATION_STATUS = 13;

    public function __construct(private readonly EvolutionGoService $evolution)
    {
    }

    /**
     * Gera (ou reaproveita) o link público de confirmação do agendamento e o
     * envia automaticamente ao paciente pelo WhatsApp da unidade.
     */
    public function store(Appointment $appointment): JsonResponse
    {
        if (!$appointment->public_token) {
            $appointment->update(['public_token' => Str::random(40)]);
        }

        $unit = $appointment->event->doctor->unitAddress;

        if (!$unit || !$unit->evolution_token) {
            return response()->json([
                'message' => 'Esta unidade não possui WhatsApp conectado. Configure em Configurações → Unidades.',
            ], 404);
        }

        $phone = $appointment->patient->phone ?? null;
        $number = $this->normalizePhone($phone);

        if (!$number) {
            return response()->json(['message' => 'Paciente sem telefone cadastrado.'], 422);
        }

        try {
            $status = $this->evolution->getStatus($unit->evolution_token);
            $connected = $status['data']['Connected'] ?? $status['Connected'] ?? false;
            $loggedIn = $status['data']['LoggedIn'] ?? $status['LoggedIn'] ?? false;

            if (!$connected || !$loggedIn) {
                return response()->json([
                    'message' => 'WhatsApp da unidade está desconectado. Reconecte em Configurações → Unidades.',
                ], 422);
            }

            $baseUrl = rtrim(config('app.frontend_url'), '/');
            $url = "{$baseUrl}/confirmar-consulta/{$appointment->public_token}";
            $message = $this->buildMessage($appointment, $url);

            $this->evolution->sendLinkMessage(
                $unit->evolution_token,
                $number,
                $message,
                $url,
                'Confirmação de Consulta',
                'Toque para confirmar, cancelar ou reagendar sua consulta',
                'https://clavaconsult.vercel.app/icons/icon-512.png',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Erro interno ao enviar a confirmação pelo WhatsApp.'], 500);
        }

        $appointment->update(['status' => self::AWAITING_CONFIRMATION_STATUS]);
        ScheduleUpdated::dispatch($appointment->event->doctor);

        return response()->json(['message' => 'Mensagem de confirmação enviada com sucesso.']);
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone ?? '');

        if ($digits === '') {
            return null;
        }

        return str_starts_with($digits, '55') ? $digits : "55{$digits}";
    }

    private function buildMessage(Appointment $appointment, string $url): string
    {
        $firstName = explode(' ', trim($appointment->patient->name))[0];
        $date = \Carbon\Carbon::parse($appointment->event->date)->format('d/m/Y');
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
