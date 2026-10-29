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
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    // Telefone (só dígitos, com DDD) e horário de trabalho (só os dias trabalhados).
    private const PROFILE_RULES = [
        'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\d{10,11}$/'],
        'work_schedule' => ['sometimes', 'nullable', 'array', 'max:7'],
        'work_schedule.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
        'work_schedule.*.start_time' => ['required', 'date_format:H:i'],
        'work_schedule.*.end_time' => ['required', 'date_format:H:i'],
    ];

    private const MESSAGES = [
        'email.unique' => 'Este e-mail já está cadastrado.',
        'phone.regex' => 'Telefone inválido. Informe DDD e número.',
        'work_schedule.*.day_of_week.distinct' => 'Cada dia da semana só pode aparecer uma vez no horário de trabalho.',
        'work_schedule.*.start_time.date_format' => 'Horário de trabalho inválido. Use o formato 00:00.',
        'work_schedule.*.end_time.date_format' => 'Horário de trabalho inválido. Use o formato 00:00.',
    ];

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

        $employees = $dbQuery->cursorPaginate(25)->withQueryString();
        Employee::withWhitelist($employees->items());

        return EmployeeResource::collection($employees);
    }

    public function show(Employee $employee): EmployeeResource
    {
        Employee::withWhitelist([$employee]);

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
        ] + self::PROFILE_RULES, self::MESSAGES);
        $input = $this->normalizeProfile($input);

        $employee = Employee::create($input);
        $user = new User($input);

        $employee->user()->save($user);

        if (array_key_exists('unit_address_ids', $input)) {
            $employee->units()->sync($input['unit_address_ids']);
        }

        Employee::withWhitelist([$employee]);

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
        ] + self::PROFILE_RULES, self::MESSAGES);
        $input = $this->normalizeProfile($input);

        $employee->update($input);
        $employee->user->update($input);

        if (array_key_exists('unit_address_ids', $input)) {
            $employee->units()->sync($input['unit_address_ids']);
        }

        Employee::withWhitelist([$employee]);

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

    /**
     * Confere que a saída é depois da entrada e guarda só os campos esperados de cada dia, em ordem
     * de domingo a sábado. (A regra "after" do Laravel registra uma exceção no log a cada dia.)
     */
    private function normalizeProfile(array $input): array
    {
        if (array_key_exists('work_schedule', $input)) {
            foreach ($input['work_schedule'] ?? [] as $i => $day) {
                if ($day['end_time'] <= $day['start_time']) {
                    throw ValidationException::withMessages([
                        "work_schedule.{$i}.end_time" => 'No horário de trabalho, a saída deve ser depois da entrada.',
                    ]);
                }
            }

            $input['work_schedule'] = collect($input['work_schedule'] ?? [])
                ->map(fn (array $day) => [
                    'day_of_week' => (int) $day['day_of_week'],
                    'start_time' => $day['start_time'],
                    'end_time' => $day['end_time'],
                ])
                ->sortBy('day_of_week')
                ->values()
                ->all();
        }

        return $input;
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
