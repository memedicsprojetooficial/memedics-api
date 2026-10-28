<?php

namespace App\Http\Controllers;

use App\Http\Resources\DoctorResource;
use App\Http\Resources\ReportResource;
use App\Models\MedicalReport;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PatientMedicalReportController extends Controller
{
    /**
     * @param Request $request
     * @param Patient $patient
     * @return AnonymousResourceCollection
     */
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        // Relacionamentos lidos por ReportResource e, nesta rota, pelo AppointmentResource embutido.
        $query = MedicalReport::with([
            'fieldData',
            'appointment.patient.address',
            'appointment.plan',
            'appointment.payments.appointment.patient',
            ...DoctorResource::relationsUnder('appointment.event.doctor'),
        ]);

        $query->where(function (Builder $query) use ($request, $patient) {
            $query->whereHas('appointment.patient', function (Builder $query) use ($patient) {
                $query->where('id', $patient->id);
            });

            $query->whereHas('appointment.event.doctor', function (Builder $query) use ($request) {
                $query->where('id', $request->user()->profile_id);
            });
        });

        $query->orWhere(function (Builder $query) use ($request, $patient) {
            $query->where('patient_id', $patient->id);
            $query->where('doctor_id', $request->user()->profile_id);
        });

        $query->where('medical_reports.status', 'committed');

        $query->orderByDesc('date');

        return ReportResource::collection($query->cursorPaginate(100)->withQueryString());
    }
}
