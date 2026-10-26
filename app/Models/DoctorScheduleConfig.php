<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorScheduleConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'slot_duration',
        'slot_label_interval',
        'confirmation_days_before',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
