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
        Schema::table('rfqs', function (Blueprint $table) {
            // Senior Operations' second review, the Head of Business
            // Development, and the General Manager can each reject an RFQ
            // back to an earlier stage — the same one column set for all
            // three (which of them it was is reject_from_stage), replacing
            // the head_of_bd_*-only columns below, which stay as history but
            // are no longer written to. See Rfq::rejectToStage().
            $table->foreignId('rejected_by')->nullable()->after('head_of_bd_reject_target_stage')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('reject_reason')->nullable()->after('rejected_at');
            $table->string('reject_from_stage')->nullable()->after('reject_reason');
            $table->string('reject_target_stage')->nullable()->after('reject_from_stage');
        });

        // Every existing Head of Business Development rejection carries
        // forward into the generic columns, so nothing on an RFQ already
        // sent back stops showing correctly.
        DB::table('rfqs')
            ->whereNotNull('head_of_bd_rejected_at')
            ->update([
                'rejected_by' => DB::raw('head_of_bd_rejected_by'),
                'rejected_at' => DB::raw('head_of_bd_rejected_at'),
                'reject_reason' => DB::raw('head_of_bd_reject_reason'),
                'reject_from_stage' => 'head_of_bd_review',
                'reject_target_stage' => DB::raw('head_of_bd_reject_target_stage'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn(['reject_target_stage', 'reject_from_stage', 'reject_reason', 'rejected_at']);
            $table->dropConstrainedForeignId('rejected_by');
        });
    }
};
