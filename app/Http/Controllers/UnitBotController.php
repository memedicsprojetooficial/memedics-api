<?php

namespace App\Http\Controllers;

use App\Models\UnitAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pausa e retomada do bot de WhatsApp de uma unidade. Com o bot pausado, o fluxo do
 * n8n não responde aos pacientes, e o atendente conversa direto pelo WhatsApp.
 */
class UnitBotController extends Controller
{
    public function pause(Request $request, UnitAddress $unit): JsonResponse
    {
        $data = $request->validate([
            // Ausente/nulo = pausa até alguém retomar manualmente.
            'hours' => 'nullable|integer|min:1|max:72',
        ]);

        $unit->update([
            'bot_paused'       => true,
            'bot_paused_until' => isset($data['hours']) ? now()->addHours($data['hours']) : null,
        ]);

        return $this->state($unit);
    }

    public function resume(UnitAddress $unit): JsonResponse
    {
        $unit->update([
            'bot_paused'       => false,
            'bot_paused_until' => null,
        ]);

        return $this->state($unit);
    }

    private function state(UnitAddress $unit): JsonResponse
    {
        return response()->json([
            'bot_paused'       => $unit->isBotPaused(),
            'bot_paused_until' => $unit->isBotPaused() ? $unit->bot_paused_until : null,
        ]);
    }
}
