<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            $table->dropColumn(['evolution_instance_id', 'evolution_token']);
        });
    }

    public function down(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            $table->string('evolution_instance_id')->nullable()->after('zip_code');
            $table->string('evolution_token')->nullable()->after('evolution_instance_id');
        });
    }
};
