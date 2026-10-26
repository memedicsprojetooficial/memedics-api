<?php

namespace App\Http\Controllers;

use App\Exceptions\ConfirmationNotSent;
use App\Models\Appointment;
use App\Services\AppointmentConfirmationService;
use Illuminate\Http\JsonResponse;
use Throwable;

class AppointmentPublicLinkController extends Controller
{
    public function __construct(private readonly AppointmentConfirmationService $confirmation)
    {
    }

    /**
     * Gera (ou reaproveita) o link público de confirmação do agendamento e o
     * envia ao paciente pelo WhatsApp da unidade (botão "Enviar Confirmação").
     */
    public function store(Appointment $appointment): JsonResponse
    {
        try {
            $this->confirmation->send($appointment);
        } catch (ConfirmationNotSent $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Erro interno ao enviar a confirmação pelo WhatsApp.'], 500);
        }

        return response()->json(['message' => 'Mensagem de confirmação enviada com sucesso.']);
    }
}
