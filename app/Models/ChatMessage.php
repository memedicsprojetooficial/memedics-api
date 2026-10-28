<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem do chat entre um médico e a recepção da unidade dele.
 */
class ChatMessage extends Model
{
    public const SENDER_DOCTOR = 'doctor';
    public const SENDER_RECEPTION = 'reception';

    // Mensagens mais antigas que isso não aparecem e são apagadas.
    public const RETENTION_DAYS = 7;

    protected $fillable = [
        'unit_address_id',
        'doctor_id',
        'sender',
        'sender_user_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDays(self::RETENTION_DAYS));
    }

    public static function pruneExpired(): void
    {
        static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }
}
