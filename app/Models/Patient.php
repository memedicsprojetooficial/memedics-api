<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperPatient
 */
class Patient extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'old_id',
        'name',
        'gender',
        'birthday',
        'document',
        'phone',
        'phone2',
        'email',
        'plan_id'
    ];

    protected static function booted()
    {
        static::deleting(function (Patient $patient) {
            if ($patient->address()->exists()) {
                $patient->address->delete();
            }

            foreach ($patient->appointments()->get() as $appointment) {
                $appointment->delete();
            }
        });
    }

    /**
     * @return HasOne
     */
    public function address(): HasOne
    {
        return $this->hasOne(Address::class);
    }

    /**
     * @return BelongsTo
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasManyThrough
     */
    public function reports(): HasManyThrough
    {
        return $this->hasManyThrough(MedicalReport::class, Appointment::class);
    }
}
