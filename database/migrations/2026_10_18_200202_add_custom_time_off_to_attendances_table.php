<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // On a half day off a custom time (half_off 'custom'): from and
            // to, "HH:MM" in the working hours' time zone — up to 4 working
            // hours. See App\Models\Attendance::timeOff().
            $table->string('half_off_start', 5)->nullable()->after('half_off');
            $table->string('half_off_end', 5)->nullable()->after('half_off_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['half_off_start', 'half_off_end']);
        });
    }
};
