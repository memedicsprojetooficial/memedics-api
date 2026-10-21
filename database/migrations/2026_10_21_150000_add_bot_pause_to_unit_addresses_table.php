<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            $table->boolean('bot_paused')->default(false)->after('waha_session_name');
            $table->timestamp('bot_paused_until')->nullable()->after('bot_paused');
        });
    }

    public function down(): void
    {
        Schema::table('unit_addresses', function (Blueprint $table) {
            $table->dropColumn(['bot_paused', 'bot_paused_until']);
        });
    }
};
