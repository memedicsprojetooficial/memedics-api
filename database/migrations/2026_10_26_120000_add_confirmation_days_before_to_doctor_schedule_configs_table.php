<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_schedule_configs', function (Blueprint $table) {
            // Nulo = envio automático de confirmação desligado para o médico.
            $table->unsignedTinyInteger('confirmation_days_before')->nullable()->after('slot_label_interval');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_schedule_configs', function (Blueprint $table) {
            $table->dropColumn('confirmation_days_before');
        });
    }
};
