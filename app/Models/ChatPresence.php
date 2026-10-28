<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Último sinal de "painel aberto" de um usuário, usado pelo chat para saber quem está online.
 */
class ChatPresence extends Model
{
    // O painel manda sinal a cada 25 s; sem sinal por mais que isso, o usuário está offline.
    public const ONLINE_SECONDS = 60;

    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['user_id', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];
}
