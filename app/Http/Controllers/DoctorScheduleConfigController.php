<?php

namespace App\Http\Controllers;

use App\Http\Resources\DoctorScheduleConfigResource;
use App\Models\Doctor;
use App\Models\DoctorScheduleConfig;
use Illuminate\Http\Request;

class DoctorScheduleConfigController extends Controller
{
    public function show(Doctor $doctor): DoctorScheduleConfigResource
    {
        $config = DoctorScheduleConfig::firstOrCreate(
            ['doctor_id' => $doctor->id],
            ['slot_duration' => '00:20:00', 'slot_label_interval' => '00:20:00']
        );

        return new DoctorScheduleConfigResource($config);
    }

    public function update(Request $request, Doctor $doctor): DoctorScheduleConfigResource
    {
        $validated = $request->validate([
            'slot_duration'       => ['required', 'in:00:15:00,00:20:00,00:30:00,00:45:00,01:00:00'],
            'slot_label_interval' => ['required', 'in:00:15:00,00:20:00,00:30:00,00:45:00,01:00:00'],
            // Nulo desliga o envio automático de confirmação para o médico.
            'confirmation_days_before' => ['nullable', 'integer', 'min:1', 'max:15'],
        ]);

        $config = DoctorScheduleConfig::updateOrCreate(
            ['doctor_id' => $doctor->id],
            $validated
        );

        return new DoctorScheduleConfigResource($config);
    }
}
