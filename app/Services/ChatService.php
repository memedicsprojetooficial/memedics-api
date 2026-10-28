<?php

namespace App\Services;

use App\Models\ChatPresence;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quem participa do chat e quem está online. Online = painel aberto, com sinal (heartbeat)
 * nos últimos ChatPresence::ONLINE_SECONDS segundos.
 */
class ChatService
{
    public function touch(User $user): void
    {
        ChatPresence::updateOrCreate(['user_id' => $user->id], ['last_seen_at' => now()]);
    }

    public function forget(User $user): void
    {
        ChatPresence::where('user_id', $user->id)->delete();
    }

    /**
     * Médico do usuário logado, se ele for médico.
     */
    public function doctorFor(User $user): ?Doctor
    {
        return $user->type === 'doctor' ? Doctor::find($user->profile_id) : null;
    }

    /**
     * Unidades do atendente logado (vazio para quem não é atendente).
     */
    public function unitIdsFor(User $user): Collection
    {
        if ($user->type !== 'employee') {
            return collect();
        }

        return DB::table('employee_unit_address')
            ->where('employee_id', $user->profile_id)
            ->pluck('unit_address_id')
            ->map(fn ($id) => (int) $id);
    }

    public function receptionOnline(int $unitId): bool
    {
        return User::where('type', 'employee')
            ->whereIn('id', $this->onlineUserIds())
            ->whereIn('profile_id', DB::table('employee_unit_address')->where('unit_address_id', $unitId)->select('employee_id'))
            ->exists();
    }

    public function doctorOnline(int $doctorId): bool
    {
        return $this->onlineDoctorIds([$doctorId])->isNotEmpty();
    }

    /**
     * @param int[] $doctorIds
     */
    public function onlineDoctorIds(iterable $doctorIds): Collection
    {
        return User::where('type', 'doctor')
            ->whereIn('id', $this->onlineUserIds())
            ->whereIn('profile_id', collect($doctorIds)->all())
            ->pluck('profile_id')
            ->map(fn ($id) => (int) $id);
    }

    private function onlineUserIds(): Builder
    {
        return ChatPresence::query()
            ->select('user_id')
            ->where('last_seen_at', '>=', now()->subSeconds(ChatPresence::ONLINE_SECONDS));
    }
}
