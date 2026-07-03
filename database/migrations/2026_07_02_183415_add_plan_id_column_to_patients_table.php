<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPlanIdColumnToPatientsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('phone2')->constrained()->nullOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
}
