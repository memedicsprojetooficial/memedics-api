<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * @mixin IdeHelperAppointment
 */
class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'status' => 1
    ];

    protected $fillable = [
        'patient_id',
        'plan_id',
        'type',
        'comment',
        'status',
        'public_token'
    ];

    protected static function booted()
    {
        static::deleting(function (Appointment $appointment) {
            $appointment->event->delete();

            foreach ($appointment->payments()->get() as $payment) {
                $payment->delete();
            }
        });
    }

    /**
     * @return MorphOne
     */
    public function event(): MorphOne
    {
        return $this->morphOne(Event::class, 'event', 'type');
    }

    /**
     * Route params for appointments are the Event id (the id used throughout
     * the schedule/calendar and returned by EventResource), not this model's
     * own id — the two tables have independent auto-increment sequences.
     *
     * @param mixed $value
     * @param string|null $field
     * @return Appointment|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $event = Event::find($value);

        if ($event && $event->event instanceof self) {
            return $event->event;
        }

        return $this->where($this->getRouteKeyName(), $value)->first();
    }

    /**
     * @return BelongsTo
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasOne
     */
    public function medicalReport(): HasOne
    {
        return $this->hasOne(MedicalReport::class);
    }

    /**
     * @return HasMany
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
