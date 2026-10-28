<?php

namespace App\Http\Controllers;

use App\Events\ChatUpdated;
use App\Models\ChatMessage;
use App\Models\Doctor;
use App\Models\UnitAddress;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Chat entre o médico e a recepção da unidade dele.
 *
 * Regras: a conversa é do médico com a recepção da unidade do cadastro dele (qualquer atendente
 * online daquela unidade vê e responde). Só se envia mensagem com o outro lado online. Atendente
 * de outra unidade não vê nem responde. Mensagens com mais de 7 dias somem.
 */
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat)
    {
    }

    /**
     * Sinal de "painel aberto" (a cada ~25 s) e o estado do chat para quem chamou.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->chat->touch($user);

        if ($doctor = $this->chat->doctorFor($user)) {
            $unit = $doctor->unitAddress;

            return response()->json([
                'role'            => ChatMessage::SENDER_DOCTOR,
                'doctor_id'       => $doctor->id,
                'unit'            => $unit ? ['id' => $unit->id, 'name' => $unit->unit_name] : null,
                'reception_online'=> $unit ? $this->chat->receptionOnline($unit->id) : false,
                'unread'          => $unit ? $this->unread($doctor->id, $unit->id, ChatMessage::SENDER_RECEPTION) : 0,
            ]);
        }

        $unitIds = $this->chat->unitIdsFor($user);

        if ($unitIds->isNotEmpty()) {
            return response()->json([
                'role'   => ChatMessage::SENDER_RECEPTION,
                'units'  => UnitAddress::whereIn('id', $unitIds)->orderBy('unit_name')->get()
                    ->map(fn (UnitAddress $u) => ['id' => $u->id, 'name' => $u->unit_name]),
                'unread' => ChatMessage::recent()
                    ->whereIn('unit_address_id', $unitIds)
                    ->where('sender', ChatMessage::SENDER_DOCTOR)
                    ->whereNull('read_at')
                    ->count(),
            ]);
        }

        // Atendente sem unidade (ou outro tipo de usuário): sem chat.
        return response()->json(['role' => null]);
    }

    /**
     * Para a recepção: médicos das suas unidades que estão online ou conversaram nos últimos 7 dias.
     */
    public function conversations(Request $request): JsonResponse
    {
        $unitIds = $this->chat->unitIdsFor($request->user());

        if ($unitIds->isEmpty()) {
            abort(403, 'Você não está vinculado a nenhuma unidade.');
        }

        $doctors = Doctor::with(['user', 'unitAddress'])->whereIn('unit_addresses_id', $unitIds)->get();
        $online = $this->chat->onlineDoctorIds($doctors->pluck('id'));

        $stats = ChatMessage::recent()
            ->whereIn('unit_address_id', $unitIds)
            ->select(
                'doctor_id',
                'unit_address_id',
                DB::raw('MAX(id) as last_id'),
                DB::raw("SUM(CASE WHEN sender = 'doctor' AND read_at IS NULL THEN 1 ELSE 0 END) as unread"),
            )
            ->groupBy('doctor_id', 'unit_address_id')
            ->get()
            // A conversa vale para a unidade atual do médico.
            ->filter(fn ($s) => (int) $s->unit_address_id === (int) $doctors->firstWhere('id', $s->doctor_id)?->unit_addresses_id)
            ->keyBy('doctor_id');

        $lastMessages = ChatMessage::whereIn('id', $stats->pluck('last_id'))->get()->keyBy('doctor_id');

        $list = $doctors
            ->filter(fn (Doctor $d) => $online->contains($d->id) || $stats->has($d->id))
            ->map(function (Doctor $d) use ($online, $stats, $lastMessages) {
                $last = $lastMessages->get($d->id);

                return [
                    'doctor_id'    => $d->id,
                    'doctor_name'  => $d->user?->name,
                    'unit_id'      => $d->unit_addresses_id,
                    'unit_name'    => $d->unitAddress?->unit_name,
                    'online'       => $online->contains($d->id),
                    'unread'       => (int) ($stats->get($d->id)->unread ?? 0),
                    'last_message' => $last ? [
                        'body'       => $last->body,
                        'sender'     => $last->sender,
                        'created_at' => $last->created_at,
                    ] : null,
                ];
            })
            ->sortBy([
                fn ($a, $b) => ($b['unread'] > 0) <=> ($a['unread'] > 0),
                fn ($a, $b) => ($b['last_message']['created_at'] ?? null) <=> ($a['last_message']['created_at'] ?? null),
                fn ($a, $b) => $b['online'] <=> $a['online'],
                fn ($a, $b) => strcmp((string) $a['doctor_name'], (string) $b['doctor_name']),
            ])
            ->values();

        return response()->json(['data' => $list]);
    }

    public function messages(Request $request, Doctor $doctor): JsonResponse
    {
        $this->participant($request, $doctor);

        $messages = ChatMessage::recent()
            ->with('senderUser')
            ->where('doctor_id', $doctor->id)
            ->where('unit_address_id', $doctor->unit_addresses_id)
            ->orderBy('id')
            ->limit(500)
            ->get();

        return response()->json([
            'data'             => $messages->map(fn (ChatMessage $m) => $this->present($m)),
            // Para habilitar ou não o envio na tela.
            'doctor_online'    => $this->chat->doctorOnline($doctor->id),
            'reception_online' => $doctor->unit_addresses_id ? $this->chat->receptionOnline($doctor->unit_addresses_id) : false,
        ]);
    }

    public function send(Request $request, Doctor $doctor): JsonResponse
    {
        $sender = $this->participant($request, $doctor);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], [
            'body.required' => 'Escreva uma mensagem.',
            'body.max'      => 'A mensagem pode ter no máximo 2000 caracteres.',
        ]);
        $body = trim($data['body']);

        if ($body === '') {
            return response()->json(['message' => 'Escreva uma mensagem.'], 422);
        }

        $unitId = (int) $doctor->unit_addresses_id;

        if ($sender === ChatMessage::SENDER_DOCTOR && !$this->chat->receptionOnline($unitId)) {
            return response()->json(['message' => 'A recepção está offline no momento. Tente de novo quando houver um atendente online.'], 409);
        }

        if ($sender === ChatMessage::SENDER_RECEPTION && !$this->chat->doctorOnline($doctor->id)) {
            return response()->json(['message' => 'O médico está offline no momento.'], 409);
        }

        // Quem envia está com o painel aberto.
        $this->chat->touch($request->user());

        $message = ChatMessage::create([
            'unit_address_id' => $unitId,
            'doctor_id'       => $doctor->id,
            'sender'          => $sender,
            'sender_user_id'  => $request->user()->id,
            'body'            => $body,
        ]);

        try {
            ChatMessage::pruneExpired();
        } catch (Throwable $e) {
            report($e);
        }

        $this->broadcast($doctor->id, $unitId, 'message', $sender);

        return response()->json(['data' => $this->present($message->load('senderUser'))], 201);
    }

    /**
     * Marca como lidas as mensagens do outro lado.
     */
    public function markRead(Request $request, Doctor $doctor): JsonResponse
    {
        $sender = $this->participant($request, $doctor);
        $other = $sender === ChatMessage::SENDER_DOCTOR ? ChatMessage::SENDER_RECEPTION : ChatMessage::SENDER_DOCTOR;

        $updated = ChatMessage::recent()
            ->where('doctor_id', $doctor->id)
            ->where('unit_address_id', $doctor->unit_addresses_id)
            ->where('sender', $other)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($updated > 0) {
            $this->broadcast($doctor->id, (int) $doctor->unit_addresses_id, 'read', $sender);
        }

        return response()->json(['updated' => $updated]);
    }

    /**
     * O médico só acessa a própria conversa; o atendente, só a de médicos das suas unidades.
     *
     * @return string o lado de quem chamou (doctor | reception)
     */
    private function participant(Request $request, Doctor $doctor): string
    {
        $user = $request->user();

        if ($user->type === 'doctor' && (int) $user->profile_id === $doctor->id) {
            if (!$doctor->unit_addresses_id) {
                abort(422, 'Seu cadastro não tem unidade. Peça para a clínica definir a sua unidade.');
            }

            return ChatMessage::SENDER_DOCTOR;
        }

        if ($doctor->unit_addresses_id && $this->chat->unitIdsFor($user)->contains((int) $doctor->unit_addresses_id)) {
            return ChatMessage::SENDER_RECEPTION;
        }

        abort(403, 'Você não participa desta conversa.');
    }

    private function unread(int $doctorId, int $unitId, string $from): int
    {
        return ChatMessage::recent()
            ->where('doctor_id', $doctorId)
            ->where('unit_address_id', $unitId)
            ->where('sender', $from)
            ->whereNull('read_at')
            ->count();
    }

    private function broadcast(int $doctorId, int $unitId, string $type, string $sender): void
    {
        // A mensagem já foi gravada; se o tempo real falhar, ela aparece na próxima atualização.
        try {
            ChatUpdated::dispatch($doctorId, $unitId, $type, $sender);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function present(ChatMessage $m): array
    {
        return [
            'id'          => $m->id,
            'doctor_id'   => $m->doctor_id,
            'sender'      => $m->sender,
            'sender_name' => $m->senderUser?->name,
            'body'        => $m->body,
            'read_at'     => $m->read_at,
            'created_at'  => $m->created_at,
        ];
    }
}
