<?php

namespace App\Http\Controllers;

use App\Http\Resources\DoctorResource;
use App\Models\Doctor;
use App\Models\ReportTab;
use App\Models\User;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $dbQuery = Doctor::with('scheduleConfig');

        if ($specialtyId = $request->query('specialty_id')) {
            $dbQuery->where('specialty_id', $specialtyId);
        }

        if ($search = $request->query('search')) {
            $like = '%' . $search . '%';
            $digits = preg_replace('/\D+/', '', $search);

            $dbQuery->where(function (Builder $query) use ($like, $digits) {
                $query->whereHas('user', function (Builder $userQuery) use ($like) {
                    $userQuery->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });

                $query->orWhere('council_number', 'like', $like)
                    ->orWhereHas('specialty', fn(Builder $q) => $q->where('name', 'like', $like));

                if ($digits !== '') {
                    $digitsLike = '%' . $digits . '%';

                    $query->orWhereRaw("REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), '/', '') LIKE ?", [$digitsLike]);
                }
            });
        }

        $perPage = min((int) $request->query('per_page', 25), 100);

        return DoctorResource::collection(
            $dbQuery->orderBy('id')->cursorPaginate($perPage)->withQueryString()
        );
    }

    /**
     * @param Doctor $doctor
     * @return DoctorResource
     */
    public function show(Doctor $doctor): DoctorResource
    {
        $doctor->loadMissing(['information', 'csatResponses', 'scheduleConfig']);

        return new DoctorResource($doctor);
    }

    /**
     * @param Request $request
     * @return DoctorResource
     * @throws FileNotFoundException
     */
    public function store(Request $request): DoctorResource
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'company_name' => ['string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'email', 'unique:users'],
            'password' => ['required', 'string', 'max:255'],
            'admin' => ['required', 'boolean'],
            'unit_addresses_id' => ['required', 'integer', 'numeric'],
            'cpf' => ['required', 'string', 'max:255', 'unique:doctors'],
            'phone' => ['string', 'max:255'],
            'council_type' => ['required', 'string', 'max:255'],
            'council_number' => ['required', 'string', 'max:255', 'unique:doctors'],
            'specialty_id' => ['required', 'exists:specialties,id'],
            'show_in_bot' => ['sometimes', 'boolean'],
        ], [
            'email.unique' => 'Este e-mail já está cadastrado.',
            'cpf.unique' => 'Este CPF já está cadastrado para outro médico.',
            'council_number.unique' => 'Este número de conselho já está cadastrado para outro médico.',
        ]);

        $doctor = Doctor::create($input);
        $user = new User($input);

        $doctor->user()->save($user);

        $config = json_decode(Storage::get('doctor_report_config.json'), true);

        foreach ($config['tabs'] as $tabConfig) {
            $tab = new ReportTab($tabConfig);

            $tab->doctor()->associate($doctor);
            $tab->save();

            $tab->reportFields()->createMany($tabConfig['fields']);
        }

        return new DoctorResource($doctor);
    }

    /**
     * @param Doctor $doctor
     * @param Request $request
     * @return DoctorResource
     */
    public function update(Doctor $doctor, Request $request): DoctorResource
    {
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'min:3', 'max:255'],
            'email' => ['sometimes', 'string', 'max:255', 'email', Rule::unique('users')->ignore($doctor->user->id)],
            'admin' => ['sometimes', 'boolean'],
            'cpf' => ['sometimes', 'string', 'max:255', Rule::unique('doctors')->ignore($doctor->id)],
            'phone' => ['sometimes', 'string', 'max:255'],
            'council_type' => ['sometimes', 'string', 'max:255'],
            'council_number' => ['sometimes', 'string', 'max:255', Rule::unique('doctors')->ignore($doctor->id)],
            'specialty_id' => ['sometimes', 'exists:specialties,id'],
            'unit_addresses_id' => ['sometimes', 'integer', 'numeric'],
            'show_in_bot' => ['sometimes', 'boolean'],
        ], [
            'email.unique' => 'Este e-mail já está cadastrado.',
            'cpf.unique' => 'Este CPF já está cadastrado para outro médico.',
            'council_number.unique' => 'Este número de conselho já está cadastrado para outro médico.',
        ]);

        $doctor->update($input);
        $doctor->user->update($input);

        return new DoctorResource($doctor);
    }

    /**
     * @param Doctor $doctor
     * @return Response
     */
    public function destroy(Doctor $doctor): Response
    {
        $doctor->user->delete();
        $doctor->delete();

        return response()->noContent();
    }

    /**
     * @param Doctor $doctor
     * @param Request $request
     * @return Response
     */
    public function changePassword(Doctor $doctor, Request $request): Response
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ]);

        $doctor->user->update(['password' => $data['password']]);

        return response()->noContent();
    }
}
