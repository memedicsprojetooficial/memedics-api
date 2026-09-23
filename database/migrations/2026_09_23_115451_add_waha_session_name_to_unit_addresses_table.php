<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            // Nome da sessão na WAHA (não é segredo — a autenticação é uma API key
            // global, ao contrário do evolution_token por instância). Convive com os
            // campos do Evolution Go até a Fase 5 da migração.
            $table->string('waha_session_name')->nullable()->after('evolution_token');
        });
    }

    public function down(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            $table->dropColumn('waha_session_name');
        });
    }
};
