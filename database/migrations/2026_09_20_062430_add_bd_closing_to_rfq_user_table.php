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
            // Business Development closes part by part too, the last step of
            // the chain: each Sourcing part the General Manager has approved
            // goes to their Ready to Close page as it comes and can be closed
            // on its own. The RFQ as a whole closes once every part has been
            // — see Rfq::closePart().
            $table->timestamp('bd_closed_at')->nullable()->after('gm_approved_by');
            $table->foreignId('bd_closed_by')->nullable()->after('bd_closed_at')->constrained('users')->nullOnDelete();
        });

        // RFQs closed before it was per part: their parts were closed along
        // with them.
        DB::table('rfq_user')
            ->whereNotNull('gm_approved_at')
            ->whereIn('rfq_id', DB::table('rfqs')->whereNotNull('bd_closed_at')->select('id'))
            ->update([
                'bd_closed_at' => DB::raw('(select rfqs.bd_closed_at from rfqs where rfqs.id = rfq_user.rfq_id)'),
                'bd_closed_by' => DB::raw('(select rfqs.bd_closed_by from rfqs where rfqs.id = rfq_user.rfq_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bd_closed_by');
            $table->dropColumn('bd_closed_at');
        });
    }
};
