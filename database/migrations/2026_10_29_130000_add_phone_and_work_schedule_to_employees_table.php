<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPhoneAndWorkScheduleToEmployeesTable extends Migration
{
    public function up()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('access_all_schedules');
            // Dias trabalhados: [{"day_of_week": 1, "start_time": "08:00", "end_time": "17:00"}, ...]
            $table->json('work_schedule')->nullable()->after('phone');
        });
    }

    public function down()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['phone', 'work_schedule']);
        });
    }
}
