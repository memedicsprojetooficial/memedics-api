<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            // Endereço por extenso (com referências) enviado na confirmação de consulta pelo WhatsApp.
            $table->string('confirmation_address', 500)->nullable()->after('zip_code');
        });
    }

    public function down(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            $table->dropColumn('confirmation_address');
        });
    }
};
