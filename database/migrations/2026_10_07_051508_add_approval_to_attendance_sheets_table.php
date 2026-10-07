<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_sheets', function (Blueprint $table) {
            // HR Manager's review of the sheet Senior Operations submitted: a
            // day's attendance only counts once it's approved — or it's
            // returned to Senior Operations, with why, to correct and submit
            // again. See App\Models\AttendanceSheet.
            $table->foreignId('approved_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('returned_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('return_reason')->nullable()->after('returned_at');
        });

        // The sheets submitted so far already count: they stay counting, as
        // approved — by nobody, from before HR Manager reviewed them.
        DB::table('attendance_sheets')->update(['approved_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_sheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['approved_at', 'returned_at', 'return_reason']);
        });
    }
};
