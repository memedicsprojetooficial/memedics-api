<?php

namespace App\Http\Controllers;

use App\Http\Resources\DoctorResource;
use App\Http\Resources\PaymentResource;
use App\Models\Doctor;
use App\Models\Event;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    /**
     * @param Request $request
     * @param Doctor $doctor
     * @return AnonymousResourceCollection
     */
    public function index(Request $request, Doctor $doctor): AnonymousResourceCollection
    {
        // Relacionamentos lidos por PaymentResource e, nesta rota, pelo AppointmentResource embutido.
        $dbQuery = Payment::with([
            'appointment.patient.address',
            'appointment.plan',
            'appointment.medicalReport.fieldData',
            ...DoctorResource::relationsUnder('appointment.event.doctor'),
        ]);

        $dbQuery->whereHas('appointment.event.doctor', function (Builder $dbQuery) use ($doctor) {
            $dbQuery->where('id', $doctor->id);
        });

        $dbQuery->whereHas('appointment.event', function (Builder $query) use ($request) {
            $query->whereBetween('date', [$request->query('start'), $request->query('end')]);
        });

        return PaymentResource::collection($dbQuery->get());
    }
}
