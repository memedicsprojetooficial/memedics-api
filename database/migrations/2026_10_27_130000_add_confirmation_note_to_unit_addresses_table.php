<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            // Aviso da unidade (ex.: tolerância de atraso) enviado na confirmação de consulta pelo WhatsApp.
            $table->string('confirmation_note', 500)->nullable()->after('confirmation_address');
        });
    }

    public function down(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            $table->dropColumn('confirmation_note');
        });
    }
};
