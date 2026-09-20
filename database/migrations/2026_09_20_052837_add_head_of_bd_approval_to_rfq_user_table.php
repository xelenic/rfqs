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
            // Head of Business Development approves part by part, like Senior
            // Operations before them: each Sourcing part Senior Operations has
            // approved can be approved here as it comes, without waiting for
            // the rest of a split. The RFQ as a whole moves on to GM Assistant
            // once every part has been approved — see
            // Rfq::approveHeadOfBdPart().
            $table->timestamp('head_of_bd_approved_at')->nullable()->after('senior_ops_reviewed_by');
            $table->foreignId('head_of_bd_approved_by')->nullable()->after('head_of_bd_approved_at')->constrained('users')->nullOnDelete();
        });

        // RFQs approved before this was per part: their parts were approved
        // along with them.
        DB::table('rfq_user')
            ->whereNotNull('senior_ops_reviewed_at')
            ->whereIn('rfq_id', DB::table('rfqs')->whereNotNull('head_of_bd_approved_at')->select('id'))
            ->update([
                'head_of_bd_approved_at' => DB::raw('(select rfqs.head_of_bd_approved_at from rfqs where rfqs.id = rfq_user.rfq_id)'),
                'head_of_bd_approved_by' => DB::raw('(select rfqs.head_of_bd_approved_by from rfqs where rfqs.id = rfq_user.rfq_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_of_bd_approved_by');
            $table->dropColumn('head_of_bd_approved_at');
        });
    }
};
