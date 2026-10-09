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
        Schema::table('rfq_user', function (Blueprint $table) {
            // When Data Entry started on the part, and who — their Start, in
            // working hours. Their time on it counts from here, not from when
            // Sourcing handed it over. See App\Models\Rfq::startDataEntryPart().
            $table->timestamp('data_entry_started_at')->nullable()->after('data_entry_completed_by');
            $table->foreignId('data_entry_started_by')->nullable()->after('data_entry_started_at')->constrained('users')->nullOnDelete();
        });

        // A part already being timed with Data Entry has, as good as, been
        // started: from when its time began, so it keeps counting as it was.
        DB::table('rfq_user')
            ->whereNotNull('completed_at')
            ->whereNull('data_entry_completed_at')
            ->update(['data_entry_started_at' => DB::raw("(select rfq_steps.started_at from rfq_steps where rfq_steps.rfq_id = rfq_user.rfq_id and rfq_steps.part_number = rfq_user.part_number and rfq_steps.step = 'data_entry' and rfq_steps.ended_at is null order by rfq_steps.started_at desc limit 1)")]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_entry_started_by');
            $table->dropColumn('data_entry_started_at');
        });
    }
};
