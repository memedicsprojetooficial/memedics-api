<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('confirmation_dispatches', function (Blueprint $table) {
            // confirmation = primeiro envio (manual ou automático); reminder = cobrança.
            $table->string('kind', 12)->default('confirmation')->after('origin');
        });
    }

    public function down(): void
    {
        Schema::table('confirmation_dispatches', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
