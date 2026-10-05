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
        Schema::table('rfq_steps', function (Blueprint $table) {
            // Whether the stretch ended by the role's own action — Mark
            // Complete, Send to Finalize, Finalize, a return, GM Assistant's
            // details — rather than the part being freed, the RFQ held or
            // closed. Only then does when it ended say when they worked: one
            // ended outside working hours is work done out of hours (see
            // RfqStep::isOutOfHoursWork()).
            $table->boolean('ended_by_role')->default(false)->after('ended_at');
        });

        // Stretches already ended were, near enough, all ended that way — the
        // backfilled ones by the saved completion times themselves.
        DB::table('rfq_steps')->whereNotNull('ended_at')->update(['ended_by_role' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_steps', function (Blueprint $table) {
            $table->dropColumn('ended_by_role');
        });
    }
};
