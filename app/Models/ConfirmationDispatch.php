<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tentativa de envio da confirmação de consulta pelo WhatsApp, com o resultado.
 */
class ConfirmationDispatch extends Model
{
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_AUTOMATIC = 'automatic';

    public const KIND_CONFIRMATION = 'confirmation';
    public const KIND_REMINDER = 'reminder';

    protected $fillable = [
        'appointment_id',
        'event_id',
        'patient_id',
        'doctor_id',
        'unit_address_id',
        'scheduled_for',
        'origin',
        'kind',
        'sent',
        'reason',
        'user_id',
    ];

    protected $casts = [
        'sent'          => 'boolean',
        'scheduled_for' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function unitAddress(): BelongsTo
    {
        return $this->belongsTo(UnitAddress::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
