<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha por tentativa de envio da confirmação de consulta (botão ou envio automático).
        Schema::create('confirmation_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id')->index();
            $table->unsignedBigInteger('event_id')->nullable()->index();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->unsignedBigInteger('unit_address_id')->nullable();
            // Data e hora da consulta no momento do envio (a consulta pode mudar ou sumir depois).
            $table->dateTime('scheduled_for')->nullable();
            $table->string('origin', 10); // manual | automatic
            $table->boolean('sent');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('user_id')->nullable(); // quem clicou, no envio manual
            $table->timestamps();

            $table->index('created_at');
        });

        // Uma linha por rodada do envio automático, para saber que rodou mesmo sem nada a enviar.
        Schema::create('confirmation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('due_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmation_runs');
        Schema::dropIfExists('confirmation_dispatches');
    }
};
