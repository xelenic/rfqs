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
            // Senior Operations' second review is given part by part: each
            // Sourcing part that has been through Data Entry can be approved
            // as it comes, without waiting for the rest of a split. The RFQ
            // as a whole moves on to Head of Business Development once every
            // part has been approved — see Rfq::approveSeniorOpsPart().
            $table->timestamp('senior_ops_reviewed_at')->nullable()->after('data_entry_completed_by');
            $table->foreignId('senior_ops_reviewed_by')->nullable()->after('senior_ops_reviewed_at')->constrained('users')->nullOnDelete();
        });

        // RFQs approved before this was per part: their parts were approved
        // along with them.
        DB::table('rfq_user')
            ->whereNotNull('data_entry_completed_at')
            ->whereIn('rfq_id', DB::table('rfqs')->whereNotNull('senior_ops_reviewed_at')->select('id'))
            ->update([
                'senior_ops_reviewed_at' => DB::raw('(select rfqs.senior_ops_reviewed_at from rfqs where rfqs.id = rfq_user.rfq_id)'),
                'senior_ops_reviewed_by' => DB::raw('(select rfqs.senior_ops_reviewed_by from rfqs where rfqs.id = rfq_user.rfq_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('senior_ops_reviewed_by');
            $table->dropColumn('senior_ops_reviewed_at');
        });
    }
};
