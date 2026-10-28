<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unidades em que o atendente trabalha (normalmente uma; duas quando cobre uma falta).
        Schema::create('employee_unit_address', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('unit_address_id')->constrained('unit_addresses')->cascadeOnDelete();
            $table->primary(['employee_id', 'unit_address_id']);
        });

        // Conversa = médico ↔ recepção da unidade dele. Mensagens com mais de 7 dias são apagadas.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_address_id')->constrained('unit_addresses')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('sender', 10); // doctor | reception
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->text('body');
            $table->timestamp('read_at')->nullable(); // lida pelo outro lado
            $table->timestamps();

            $table->index(['doctor_id', 'unit_address_id', 'created_at']);
            $table->index('created_at');
        });

        // Último sinal de "painel aberto" de cada usuário; online = sinal nos últimos 60 s.
        Schema::create('chat_presences', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->timestamp('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_presences');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('employee_unit_address');
    }
};
