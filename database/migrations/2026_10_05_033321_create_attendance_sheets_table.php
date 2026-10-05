<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Senior Operations' attendance sheet for a day: who of Sourcing, Data
        // Entry and GM Assistant was present, and who was absent — one row per
        // person in attendances. See App\Models\AttendanceSheet.
        Schema::create('attendance_sheets', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // An installation already tracking time starts needing the daily sheet
        // from today — what's been tracked so far keeps counting as it did. A
        // new one starts without it, until it's set in Settings.
        if (DB::table('rfq_steps')->exists() && ! DB::table('settings')->where('key', 'attendance_since')->exists()) {
            $zone = DB::table('settings')->where('key', 'timezone')->value('value') ?: 'Asia/Colombo';

            DB::table('settings')->insert([
                'key' => 'attendance_since',
                'value' => Carbon::now($zone)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // The settings are cached whole — see App\Models\Setting.
            Cache::forget('settings');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_sheets');

        DB::table('settings')->where('key', 'attendance_since')->delete();
        Cache::forget('settings');
    }
};
