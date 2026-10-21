<?php

use App\Http\Controllers\Bot\BotController;
use App\Http\Controllers\Bot\BotSessionController;
use App\Http\Controllers\Bot\CsatController;
use App\Http\Controllers\Bot\WhatsappWhiteListController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bot Routes (WhatsApp / N8N)
|--------------------------------------------------------------------------
|
| Autenticação via header X-Bot-Key (valor em BOT_API_KEY no .env).
|
*/

Route::middleware('bot.key')->prefix('bot')->group(function () {

    // Especialidades
    Route::get('/specialties', [BotController::class, 'specialties']);

    // Médicos por especialidade  →  ?specialty_id=X
    // Médico por ID           →  ?doctor_id=X
    Route::get('/doctors', [BotController::class, 'doctors']);

    // Horários disponíveis  →  ?date=Y-m-d&duration=30
    Route::get('/doctors/{doctor}/available-slots', [BotController::class, 'availableSlots']);

    // Próximos dias com horário livre do médico (até 3 meses à frente)
    Route::get('/doctors/{doctor}/next-availability', [BotController::class, 'nextAvailability']);

    // Buscar paciente por telefone  →  ?phone=X
    Route::get('/patients/find', [BotController::class, 'findPatient']);

    // Criar paciente (cadastro rápido via bot)
    Route::post('/patients', [BotController::class, 'createPatient']);

    // Agendamentos futuros do paciente
    Route::get('/patients/{patient}/appointments', [BotController::class, 'patientAppointments']);

    // Criar agendamento
    Route::post('/doctors/{doctor}/appointments', [BotController::class, 'createAppointment']);

    // Confirmar / cancelar agendamento
    Route::patch('/appointments/{appointment}/status', [BotController::class, 'updateAppointmentStatus']);

    // Unidade da sessão da WAHA + estado de pausa do bot
    Route::get('/units/by-session/{session}', [BotController::class, 'unitBySession']);

    // -------------------------------------------------------------------------
    // Sessões (estado da conversa)
    // -------------------------------------------------------------------------

    // Busca ou cria sessão pelo número do WhatsApp
    Route::get('/sessions/{phone}', [BotSessionController::class, 'get']);

    // Atualiza step / mode / data / history (parcial, com merge opcional)
    Route::patch('/sessions/{phone}', [BotSessionController::class, 'update']);

    // Reseta sessão para idle (fim de fluxo ou escalada encerrada)
    Route::delete('/sessions/{phone}', [BotSessionController::class, 'reset']);

    // CSAT — avaliações de atendimento
    Route::post('/csat', [CsatController::class, 'store']);

    // WhatsApp White Lists
    Route::get('/whatsapp-white-lists', [WhatsappWhiteListController::class, 'index']);
    
});
