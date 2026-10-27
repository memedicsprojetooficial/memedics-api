<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperUnitAddress
 */
class UnitAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'unit_name',
        'street',
        'number',
        'complementary',
        'neighborhood',
        'city',
        'state',
        'zip_code',
        'confirmation_address',
        'waha_session_name',
        'bot_paused',
        'bot_paused_until',
    ];

    protected $casts = [
        'bot_paused'       => 'boolean',
        'bot_paused_until' => 'datetime',
    ];

    /**
     * Pausa vale enquanto `bot_paused` estiver ligado e, se houver prazo, ele ainda
     * não tiver vencido — a retomada por prazo é calculada na leitura, sem job.
     */
    public function isBotPaused(): bool
    {
        return $this->bot_paused
            && ($this->bot_paused_until === null || $this->bot_paused_until->isFuture());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(UnitBusinessHour::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(UnitRoom::class);
    }
}
