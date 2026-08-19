<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperDoctor
 */
class Doctor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'unit_addresses_id',
        'cpf',
        'phone',
        'council_type',
        'council_number',
        'specialty_id',
        'show_in_bot',
    ];

    protected $casts = [
        'show_in_bot' => 'boolean',
    ];

    /**
     * @return MorphOne
     */
    public function user(): MorphOne
    {
        return $this->morphOne(User::class, 'profile', 'type');
    }

    /**
     * @return BelongsTo
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    /**
     * @return HasMany
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasManyThrough
     */
    public function appointments(): HasManyThrough
    {
        return $this->hasManyThrough(Appointment::class, Event::class);
    }

    public function reportTabs(): HasMany
    {
        return $this->hasMany(ReportTab::class);
    }

    public function reportFields(): HasManyThrough
    {
        return $this->hasManyThrough(ReportField::class, ReportTab::class);
    }

    public function unitAddress(): BelongsTo
    {
        return $this->belongsTo(UnitAddress::class, 'unit_addresses_id');
    }

    public function workTimes(): HasMany
    {
        return $this->hasMany(WorkTime::class);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class)
            ->withPivot('consultation_value')
            ->withTimestamps();
    }

    public function information(): HasMany
    {
        return $this->hasMany(DoctorInformation::class);
    }

    public function csatResponses(): HasMany
    {
        return $this->hasMany(CsatDoctorResponse::class);
    }

    public function scheduleConfig(): HasOne
    {
        return $this->hasOne(DoctorScheduleConfig::class);
    }
}
