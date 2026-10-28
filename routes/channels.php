<?php

use App\Models\User;
use App\Services\ChatService;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('schedule.{doctorId}', function (User $user, int $doctorId) {
    return true;
});

// Chat: o médico ouve só a própria conversa; o atendente, as das unidades em que trabalha.
Broadcast::channel('chat.doctor.{doctorId}', function (User $user, int $doctorId) {
    return $user->type === 'doctor' && (int) $user->profile_id === $doctorId;
});

Broadcast::channel('chat.unit.{unitId}', function (User $user, int $unitId) {
    return app(ChatService::class)->unitIdsFor($user)->contains($unitId);
});
