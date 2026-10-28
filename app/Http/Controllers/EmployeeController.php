<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $dbQuery = Employee::with(['user', 'units'])
            ->join('users', function ($join) {
                $join->on('users.profile_id', '=', 'employees.id')
                    ->where('users.type', '=', 'employee');
            })
            ->select('employees.*', 'users.name as name')
            ->orderBy('users.name')
            ->orderBy('employees.id');

        if ($request->query('search')) {
            $dbQuery->where(function (Builder $query) use ($request) {
                $query->where('users.name', 'like', sprintf('%s%%', $request->query('search')));
                $query->orWhere('users.email', 'like', sprintf('%s%%', $request->query('search')));
            });
        }

        return EmployeeResource::collection($dbQuery->cursorPaginate(25)->withQueryString());
    }

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee);
    }

    public function store(Request $request): EmployeeResource
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'email', 'unique:users'],
            'password' => ['required', 'string', 'max:255'],
            'admin' => ['required', 'boolean'],
            'access_all_schedules' => ['required', 'boolean'],
            'unit_address_ids' => ['sometimes', 'array'],
            'unit_address_ids.*' => ['integer', 'exists:unit_addresses,id'],
        ], [
            'email.unique' => 'Este e-mail já está cadastrado.',
        ]);

        $employee = Employee::create($input);
        $user = new User($input);

        $employee->user()->save($user);

        if (array_key_exists('unit_address_ids', $input)) {
            $employee->units()->sync($input['unit_address_ids']);
        }

        return new EmployeeResource($employee);
    }

    public function update(Employee $employee, Request $request): EmployeeResource
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'email', Rule::unique('users')->ignore($employee->user->id)],
            'admin' => ['required', 'boolean'],
            'access_all_schedules' => ['required', 'boolean'],
            'unit_address_ids' => ['sometimes', 'array'],
            'unit_address_ids.*' => ['integer', 'exists:unit_addresses,id'],
        ], [
            'email.unique' => 'Este e-mail já está cadastrado.',
        ]);

        $employee->update($input);
        $employee->user->update($input);

        if (array_key_exists('unit_address_ids', $input)) {
            $employee->units()->sync($input['unit_address_ids']);
        }

        return new EmployeeResource($employee);
    }

    public function destroy(Employee $employee, Request $request): Response
    {
        if ($employee->user && $request->user()?->is($employee->user)) {
            abort(422, 'Não é possível excluir o próprio usuário.');
        }

        $employee->user->delete();
        $employee->delete();

        return response()->noContent();
    }

    public function changePassword(Employee $employee, Request $request): Response
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ]);

        $employee->user->update(['password' => $data['password']]);

        return response()->noContent();
    }
}
