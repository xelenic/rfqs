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
            // GM Assistant and the General Manager work part by part too, like
            // Senior Operations and the Head of Business Development before
            // them: each Sourcing part the Head has approved goes to GM
            // Assistant as it comes, and on to the General Manager once they've
            // added their details to it. The RFQ as a whole moves on when every
            // part has been through each step — see
            // Rfq::recordGmAssistantPart() and approveGmPart().
            $table->timestamp('gm_assistant_completed_at')->nullable()->after('head_of_bd_approved_by');
            $table->foreignId('gm_assistant_completed_by')->nullable()->after('gm_assistant_completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('gm_approved_at')->nullable()->after('gm_assistant_completed_by');
            $table->foreignId('gm_approved_by')->nullable()->after('gm_approved_at')->constrained('users')->nullOnDelete();
        });

        // RFQs that got this far before it was per part: their parts went
        // through along with them.
        foreach ([
            'gm_assistant_completed' => 'head_of_bd_approved_at',
            'gm_approved' => 'gm_assistant_completed_at',
        ] as $step => $earlierStep) {
            DB::table('rfq_user')
                ->whereNotNull($earlierStep)
                ->whereIn('rfq_id', DB::table('rfqs')->whereNotNull("{$step}_at")->select('id'))
                ->update([
                    "{$step}_at" => DB::raw("(select rfqs.{$step}_at from rfqs where rfqs.id = rfq_user.rfq_id)"),
                    "{$step}_by" => DB::raw("(select rfqs.{$step}_by from rfqs where rfqs.id = rfq_user.rfq_id)"),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gm_approved_by');
            $table->dropConstrainedForeignId('gm_assistant_completed_by');
            $table->dropColumn(['gm_approved_at', 'gm_assistant_completed_at']);
        });
    }
};
