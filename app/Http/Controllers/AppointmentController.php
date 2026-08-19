<?php

namespace App\Http\Controllers;

use App\Events\ScheduleUpdated;
use App\Http\Controllers\AppointmentLogController;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\EventResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Event;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AppointmentController extends Controller
{
    /**
     * @param Request $request
     * @param Doctor $doctor
     * @return AnonymousResourceCollection
     */
    public function index(Request $request, Doctor $doctor): AnonymousResourceCollection
    {
        $query = $doctor->events()->whereHasMorph('event', [Appointment::class], function (Builder $query) use ($request) {
            if ($request->query('patient_id')) {
                $query->where('patient_id', $request->query('patient_id'));
            }
        });

        $query->orderBy('date', 'DESC')->orderBy('id', 'DESC');

        return EventResource::collection($query->cursorPaginate(25)->withQueryString());
    }

    /**
     * @param Appointment $appointment
     * @return AppointmentResource
     */
    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment);
    }

    /**
     * @param Request $request
     * @param Doctor $doctor
     * @return AppointmentResource
     */
    public function store(Request $request, Doctor $doctor): AppointmentResource
    {
        $input = $request->validate([
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'time' => ['required', 'string', 'date_format:H:i'],
            'duration' => ['required', 'string', 'date_format:H:i'],
            'patient_id' => ['required', 'exists:patients,id'],
            'plan_id' => ['required', 'exists:plans,id'],
            'type' => ['required', 'string', 'in:first,default,return,free,blocked,online,medical-fitting,procedure,exam'],
            'payments' => ['array'],
            'payments.*.amount' => ['required'],
            'payments.*.description' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:255']
        ]);

        $appointment = new Appointment($input);
        $appointment->save();
        $event = new Event(array_merge($input, ['doctor_id' => $doctor->id]));

        $appointment->event()->save($event);

        if (isset($input['payments'])) {
            $payments = [];

            foreach ($input['payments'] as $payment) {
                $payments[] = new Payment($payment);
            }

            $appointment->payments()->saveMany($payments);
        }

        AppointmentLogController::log($appointment->id, 'create');
        ScheduleUpdated::dispatch($doctor);

        return new AppointmentResource($appointment);
    }

    /**
     * @param Appointment $appointment
     * @param Request $request
     * @return AppointmentResource
     */
    public function update(Appointment $appointment, Request $request): AppointmentResource
    {
        $input = $request->validate([
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'time' => ['required', 'string', 'date_format:H:i'],
            'duration' => ['nullable', 'string', 'date_format:H:i'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'type' => ['nullable', 'string', 'in:first,default,return,free,blocked,online,medical-fitting,procedure,exam'],
            'payments' => ['array'],
            'payments.*.amount' => ['required'],
            'payments.*.description' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:255']
        ]);

        $appointment->update($input);
        $appointment->event->update($input);

        if (isset($input['payments'])) {
            $appointment->payments()->delete();

            $payments = [];

            foreach ($input['payments'] as $payment) {
                $payments[] = new Payment($payment);
            }

            $appointment->payments()->saveMany($payments);
        }

        AppointmentLogController::log($appointment->id, 'update');
        ScheduleUpdated::dispatch($appointment->event->doctor);

        return new AppointmentResource($appointment);
    }

    /**
     * @param Appointment $appointment
     * @return Response
     */
    public function destroy(Appointment $appointment): Response
    {
        $appointmentId = $appointment->id;
        $doctor = $appointment->event->doctor;

        $appointment->event->delete();
        $appointment->delete();

        AppointmentLogController::log($appointmentId, 'delete');
        ScheduleUpdated::dispatch($doctor);

        return response()->noContent();
    }
}
