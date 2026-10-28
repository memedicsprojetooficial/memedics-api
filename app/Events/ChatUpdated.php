<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Algo mudou numa conversa do chat (mensagem nova ou lida). Vai para o médico e para a
 * recepção da unidade; o painel recarrega a conversa ao receber.
 */
class ChatUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param string $type message | read
     */
    public function __construct(
        private readonly int $doctorId,
        private readonly int $unitId,
        private readonly string $type,
        private readonly ?string $sender = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.doctor.{$this->doctorId}"),
            new PrivateChannel("chat.unit.{$this->unitId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'doctor_id'       => $this->doctorId,
            'unit_address_id' => $this->unitId,
            'type'            => $this->type,
            'sender'          => $this->sender,
        ];
    }
}
