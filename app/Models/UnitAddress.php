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
        'evolution_instance_id',
        'evolution_token',
    ];

    protected $hidden = [
        'evolution_token',
    ];

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
