<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @mixin IdeHelperEmployee
 */
class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_all_schedules',
        'phone',
        'work_schedule',
    ];

    protected $casts = [
        // Só os dias trabalhados, ordenados: [{"day_of_week": 1, "start_time": "08:00", "end_time": "17:00"}]
        'work_schedule' => 'array',
    ];

    /**
     * Id da entrada da whitelist do bot com o telefone do funcionário; preenchido por withWhitelist().
     */
    public ?int $whitelistId = null;

    /**
     * Preenche whitelistId de vários funcionários com uma consulta só.
     */
    public static function withWhitelist(iterable $employees): void
    {
        $employees = collect($employees);
        $phones = $employees->map(fn (self $employee) => PhoneNumber::forWhatsapp($employee->phone))->filter()->unique();
        $ids = $phones->isEmpty() ? collect() : WhatsappWhiteList::whereIn('phone', $phones)->pluck('id', 'phone');

        foreach ($employees as $employee) {
            $employee->whitelistId = $ids->get(PhoneNumber::forWhatsapp($employee->phone));
        }
    }

    /**
     * @return MorphOne
     */
    public function user(): MorphOne
    {
        return $this->morphOne(User::class, 'profile', 'type');
    }

    /**
     * Unidades em que o atendente trabalha. Define com quais médicos ele conversa no chat.
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(UnitAddress::class, 'employee_unit_address');
    }

    public function csatResponses(): HasMany
    {
        return $this->hasMany(CsatReceptionistResponse::class);
    }
}
