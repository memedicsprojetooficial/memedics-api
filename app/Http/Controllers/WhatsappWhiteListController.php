<?php

namespace App\Http\Controllers;

use App\Models\WhatsappWhiteList;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Whitelist do bot: números que o bot do WhatsApp não responde (a conversa fica com o
 * atendente). O n8n lê a lista por GET /api/bot/whatsapp-white-lists.
 */
class WhatsappWhiteListController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WhatsappWhiteList::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'phone'       => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $phone = PhoneNumber::forWhatsapp($data['phone']);

        // DDI + DDD + número: 12 (fixo) ou 13 (celular) dígitos.
        if (!$phone || strlen($phone) < 12 || strlen($phone) > 13) {
            throw ValidationException::withMessages(['phone' => 'Telefone inválido. Informe DDD e número.']);
        }

        if (WhatsappWhiteList::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Este número já está na whitelist.']);
        }

        $entry = WhatsappWhiteList::create(['phone' => $phone] + $data);

        return response()->json($entry, 201);
    }

    public function destroy(WhatsappWhiteList $whatsappWhiteList): JsonResponse
    {
        $whatsappWhiteList->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Se um telefone (em qualquer formato) já está na whitelist.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $entry = WhatsappWhiteList::where('phone', PhoneNumber::forWhatsapp($request->query('phone')))->first();

        return response()->json(['listed' => (bool) $entry, 'id' => $entry?->id]);
    }
}
