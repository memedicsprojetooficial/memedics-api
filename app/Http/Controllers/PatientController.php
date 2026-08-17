<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Http\Resources\PatientResource;
use App\Models\Address;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $dbQuery = Patient::query();

        if ($doctorId = $request->query('doctor_id')) {
            $dbQuery->whereHas('appointments.event', function ($query) use ($doctorId) {
                $query->where('doctor_id', $doctorId);
            });
        }

        if ($search = $request->query('search')) {
            $like = '%' . $search . '%';
            $digits = preg_replace('/\D+/', '', $search);

            $dbQuery->where(function ($query) use ($like, $digits) {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);

                if ($digits !== '') {
                    $digitsLike = '%' . $digits . '%';

                    $query->orWhereRaw("REPLACE(REPLACE(REPLACE(document, '.', ''), '-', ''), '/', '') LIKE ?", [$digitsLike])
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '') LIKE ?", [$digitsLike]);
                }
            });
        }

        return PatientResource::collection($dbQuery->cursorPaginate(25)->withQueryString());
    }

    /**
     * @param Patient $patient
     * @return PatientResource
     */
    public function show(Patient $patient): PatientResource
    {
        return new PatientResource($patient->load('plan'));
    }

    /**
     * @param Request $request
     * @return PatientResource
     */
    public function store(Request $request): PatientResource
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'birthday' => ['nullable', 'string', 'date_format:Y-m-d'],
            'gender' => ['nullable', 'string', 'in:female,male'],
            'document' => ['nullable', 'string', 'max:255', 'unique:patients'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.number' => ['nullable', 'string', 'max:255'],
            'address.complementary' => ['nullable', 'string', 'max:255'],
            'address.neighborhood' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.state' => ['nullable', 'string', 'max:255'],
            'address.zip_code' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'phone2' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255', 'email'],
            'plan_id' => ['nullable', 'exists:plans,id'],
        ], [
            'document.unique' => 'Este CPF/CNPJ já está cadastrado para outro paciente.',
        ]);

        $patient = Patient::create($input);

        if (!empty($input['address'])) {
            $address = new Address($input['address']);

            $patient->address()->save($address);
        }

        return new PatientResource($patient);
    }

    /**
     * @param Patient $patient
     * @param Request $request
     * @return PatientResource
     */
    public function update(Patient $patient, Request $request): PatientResource
    {
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:255'],
            'birthday' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:female,male'],
            'document' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('patients')->ignore($patient->id)],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.number' => ['nullable', 'string', 'max:255'],
            'address.complementary' => ['nullable', 'string', 'max:255'],
            'address.neighborhood' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.state' => ['nullable', 'string', 'max:255'],
            'address.zip_code' => ['nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:255'],
            'phone2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255', 'email'],
            'plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
        ], [
            'document.unique' => 'Este CPF/CNPJ já está cadastrado para outro paciente.',
        ]);

        $patient->update($input);

        if (!empty($input['address'])) {
            if ($patient->address()->exists()) {
                $patient->address->update($input['address']);
            } else {
                $address = new Address($input['address']);
                $patient->address()->save($address);
            }
        }

        return new PatientResource($patient);
    }

    /**
     * @param Patient $patient
     * @return AnonymousResourceCollection
     */
    public function appointments(Patient $patient): AnonymousResourceCollection
    {
        $query = Event::whereHasMorph('event', [Appointment::class], function (Builder $query) use ($patient) {
            $query->where('patient_id', $patient->id);
        });

        $query->orderBy('date', 'DESC')->orderBy('id', 'DESC');

        return EventResource::collection($query->cursorPaginate(25)->withQueryString());
    }

    /**
     * @param Patient $patient
     * @return Response
     */
    public function destroy(Patient $patient): Response
    {
        $patient->delete();

        return response()->noContent();
    }
}
