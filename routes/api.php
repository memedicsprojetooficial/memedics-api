<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppointmentMedicalReportController;
use App\Http\Controllers\AppointmentStatusController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlockedTimeController;
use App\Http\Controllers\CIDController;
use App\Http\Controllers\CouncilController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\MedicalReportController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientMedicalReportController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportConfigController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\UnitAddressesController;
use App\Http\Controllers\UnitBusinessHoursController;
use App\Http\Controllers\UnitRoomController;
use App\Http\Controllers\UpdatePasswordController;
use App\Http\Controllers\DoctorPlanController;
use App\Http\Controllers\DoctorInformationController;
use App\Http\Controllers\EvolutionGoController;
use App\Http\Controllers\DoctorScheduleConfigController;
use App\Http\Controllers\WorkTimeController;
use App\Http\Resources\UserResource;
use App\Jobs\ImportReportsFromJson;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\MedicalReport;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\ReportTab;
use App\Models\Specialty;
use App\Models\UnitAddress;
use App\Models\User;
use App\Notifications\ImportReportsCompleted;
use App\Notifications\ImportReportsFailed;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Rotas públicas (sem autenticação)
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return new UserResource($request->user());
    });

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/change-password', [ProfileController::class, 'changePassword']);

    Route::get('/specialties', [SpecialtyController::class, 'index'])->can('viewAny', Specialty::class);
    Route::get('/specialties/{specialty}', [SpecialtyController::class, 'show'])->can('view', Specialty::class);
    Route::post('/specialties', [SpecialtyController::class, 'store'])->can('create', Specialty::class);
    Route::put('/specialties/{specialty}', [SpecialtyController::class, 'update'])->can('update', Specialty::class);
    Route::delete('/specialties/{specialty}', [SpecialtyController::class, 'destroy'])->can('delete', Specialty::class);

    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::put('/companies/{company}', [CompanyController::class, 'update']);
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);

    Route::get('/unit-adresses', [UnitAddressesController::class, 'index'])->can('viewAny', UnitAddress::class);
    Route::post('/unit-adresses', [UnitAddressesController::class, 'store'])->can('create', UnitAddress::class);
    Route::get('/unit-adresses/{unitAddress}', [UnitAddressesController::class, 'show'])->can('view', UnitAddress::class);
    Route::put('/unit-adresses/{unitAddress}', [UnitAddressesController::class, 'update'])->can('update', UnitAddress::class);
    Route::delete('/unit-adresses/{unitAddress}', [UnitAddressesController::class, 'destroy'])->can('delete', UnitAddress::class);

    Route::get('/unit-adresses/{unitAddress}/business-hours', [UnitBusinessHoursController::class, 'index']);
    Route::post('/unit-adresses/{unitAddress}/business-hours', [UnitBusinessHoursController::class, 'store']);
    Route::put('/unit-adresses/{unitAddress}/business-hours/{businessHour}', [UnitBusinessHoursController::class, 'update']);
    Route::delete('/unit-adresses/{unitAddress}/business-hours/{businessHour}', [UnitBusinessHoursController::class, 'destroy']);

    Route::get('/unit-adresses/{unitAddress}/rooms', [UnitRoomController::class, 'index']);
    Route::post('/unit-adresses/{unitAddress}/rooms', [UnitRoomController::class, 'store']);
    Route::get('/unit-adresses/{unitAddress}/rooms/{unitRoom}', [UnitRoomController::class, 'show']);
    Route::put('/unit-adresses/{unitAddress}/rooms/{unitRoom}', [UnitRoomController::class, 'update']);
    Route::delete('/unit-adresses/{unitAddress}/rooms/{unitRoom}', [UnitRoomController::class, 'destroy']);

    Route::get('/plans', [PlanController::class, 'index'])->can('viewAny', Plan::class);
    Route::get('/plans/{plan}', [PlanController::class, 'show'])->can('view', Plan::class);
    Route::post('/plans', [PlanController::class, 'store'])->can('create', Plan::class);
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->can('update', Plan::class);
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->can('delete', Plan::class);

    Route::get('/doctors', [DoctorController::class, 'index'])->can('viewAny', Doctor::class);
    Route::get('/doctors/{doctor}', [DoctorController::class, 'show'])->can('view', Doctor::class);
    Route::post('/doctors', [DoctorController::class, 'store'])->can('create', Doctor::class);
    Route::put('/doctors/{doctor}', [DoctorController::class, 'update'])->can('update', Doctor::class);
    Route::delete('/doctors/{doctor}', [DoctorController::class, 'destroy'])->can('delete', Doctor::class);

    Route::get('/employees', [EmployeeController::class, 'index'])->can('viewAny', Employee::class);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->can('view', Employee::class);
    Route::post('/employees', [EmployeeController::class, 'store'])->can('create', Employee::class);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->can('update', Employee::class);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->can('delete', Employee::class);

    Route::get('/patients', [PatientController::class, 'index'])->can('viewAny', Patient::class);
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->can('view', Patient::class);
    Route::post('/patients', [PatientController::class, 'store'])->can('create', Patient::class);
    Route::put('/patients/{patient}', [PatientController::class, 'update'])->can('update', Patient::class);
    Route::delete('/patients/{patient}', [PatientController::class, 'destroy'])->can('delete', Patient::class);

    Route::get('/doctors/{doctor}/appointments', [AppointmentController::class, 'index'])->can('viewAny', [Appointment::class, 'doctor']);

    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::post('/doctors/{doctor}/appointments', [AppointmentController::class, 'store']);
    Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update']);
    Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy']);

    Route::put('/appointments/{appointment}/status', [AppointmentStatusController::class, 'store']);

    Route::get('/unavailable-times', [BlockedTimeController::class, 'index']);
    Route::get('/unavailable-times/{unavailableTime}', [BlockedTimeController::class, 'show']);
    Route::post('/doctors/{doctor}/unavailable-times', [BlockedTimeController::class, 'store']);
    Route::put('/unavailable-times/{unavailableTime}', [BlockedTimeController::class, 'update']);
    Route::delete('/unavailable-times/{unavailableTime}', [BlockedTimeController::class, 'destroy']);

    Route::get('/doctors/{doctor}/schedule', [ScheduleController::class, 'index']);

    /* RELATÓRIOS MÉDICOS/PRONTUÁRIOS COMEÇAM AQUI */

    Route::get('/appointments/{appointment}/medical-report', [AppointmentMedicalReportController::class, 'show'])->can('view', [MedicalReport::class, 'appointment']);
    Route::post('/appointments/{appointment}/medical-report', [AppointmentMedicalReportController::class, 'store'])->can('create', [MedicalReport::class, 'appointment']);
    Route::put('/appointments/{appointment}/medical-report', [AppointmentMedicalReportController::class, 'update']);
    Route::delete('/appointments/{appointment}/medical-report', [AppointmentMedicalReportController::class, 'destroy'])->can('delete', [MedicalReport::class, 'appointment']);

    Route::get('/patients/{patient}/medical-history', [PatientMedicalReportController::class, 'index']);//->can('viewAny', MedicalReport::class);
    Route::get('/patients/{patient}/appointments', [PatientController::class, 'appointments']);

    /* Pega as siglas dos conselhos para mostrar no select list de cadastro de medicos e edição*/

    Route::get('/councils', [CouncilController::class, 'index']);

    /* RELATÓRIOS MÉDICOS TERMINAM AQUI */

    Route::get('/doctors/{doctor}/payments', [PaymentController::class, 'index']);

    Route::get('/doctors/{doctor}/plans', [DoctorPlanController::class, 'index']);
    Route::post('/doctors/{doctor}/plans', [DoctorPlanController::class, 'store']);
    Route::put('/doctors/{doctor}/plans/{plan}', [DoctorPlanController::class, 'update']);
    Route::delete('/doctors/{doctor}/plans/{plan}', [DoctorPlanController::class, 'destroy']);

    Route::get('/doctors/{doctor}/information', [DoctorInformationController::class, 'index']);
    Route::post('/doctors/{doctor}/information', [DoctorInformationController::class, 'store']);
    Route::put('/doctors/{doctor}/information/{information}', [DoctorInformationController::class, 'update']);
    Route::delete('/doctors/{doctor}/information/{information}', [DoctorInformationController::class, 'destroy']);

    Route::get('/doctors/{doctor}/work-times', [WorkTimeController::class, 'index']);
    Route::post('/doctors/{doctor}/work-times', [WorkTimeController::class, 'store']);
    Route::put('/doctors/{doctor}/work-times/{workTime}', [WorkTimeController::class, 'update']);
    Route::delete('/doctors/{doctor}/work-times/{workTime}', [WorkTimeController::class, 'destroy']);

    // Redefinição de senha do usuário
    Route::put('/users/{id}/password', [UpdatePasswordController::class, 'update']);

    // Retorna configurações do prontuário para o médico.
    Route::get('doctors/{doctor}/report-config', [ReportConfigController::class, 'show']);

    Route::get('/doctors/{doctor}/schedule-config', [DoctorScheduleConfigController::class, 'show']);
    Route::put('/doctors/{doctor}/schedule-config', [DoctorScheduleConfigController::class, 'update']);

    Route::get('cids', [CIDController::class, 'index']);

    // ── Evolution Go (WhatsApp) ───────────────────────────────────────────────
    Route::prefix('evolution')->group(function () {
        // Listagem global
        Route::get('/', [EvolutionGoController::class, 'index']);

        // Operações por unidade
        Route::post  ('/units/{unit}',            [EvolutionGoController::class, 'storeForUnit']);
        Route::post  ('/units/{unit}/connect',    [EvolutionGoController::class, 'connectUnit']);
        Route::post  ('/units/{unit}/disconnect', [EvolutionGoController::class, 'disconnectUnit']);
        Route::delete('/units/{unit}',            [EvolutionGoController::class, 'destroyUnit']);
        Route::get   ('/units/{unit}/qr',         [EvolutionGoController::class, 'qrCodeUnit']);
        Route::get   ('/units/{unit}/status',     [EvolutionGoController::class, 'statusUnit']);
    });

    Route::post('medical-reports', [MedicalReportController::class, 'store']);

    Route::get('home', function () {
        return response()->noContent();
    });

    Route::post('/import', function () {
        $doctors = collect([7, 10, 11, 13, 18, 22, 26]);

        Bus::batch($doctors->map(fn($doctor) => new ImportReportsFromJson($doctor)))
            ->then(function (Batch $batch) {
                $users = User::whereIn('id', [2, 10])->get();

                Notification::send($users, new ImportReportsCompleted($batch));
            })
            ->catch(function (Batch $batch) {
                $users = User::whereIn('id', [2, 10])->get();

                Notification::send($users, new ImportReportsFailed($batch));
            })
            ->dispatch();

        return 'Ok.';
    });

    Route::get('/ajustar-medicos', function () {
        $config = json_decode(Storage::get('doctor_report_config.json'), true);
        $doctors = Doctor::whereIn('id', [40, 41, 42, 43])->get();

        foreach ($doctors as $doctor) {
            foreach ($config['tabs'] as $tabConfig) {
                $tab = new ReportTab($tabConfig);

                $tab->doctor()->associate($doctor);
                $tab->save();

                $tab->reportFields()->createMany($tabConfig['fields']);
            }
        }
    });
});

