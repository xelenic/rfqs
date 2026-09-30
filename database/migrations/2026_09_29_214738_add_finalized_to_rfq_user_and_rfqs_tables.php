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
            // Data Entry no longer sends a part straight on to Senior
            // Operations' review: "Send to Finalize" hands it back to its
            // Sourcing member, whose Finalize is what sends it on — see
            // Rfq::finalizePart().
            $table->timestamp('finalized_at')->nullable()->after('data_entry_completed_by');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('rfqs', function (Blueprint $table) {
            // The whole RFQ's, once every part has been finalized — mirrors
            // the parts', same as data_entry_completed_at.
            $table->timestamp('finalized_at')->nullable()->after('data_entry_completed_at');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
        });

        // Parts already past Data Entry went straight on under the old flow —
        // they count as finalized, by their own Sourcing member, then.
        DB::table('rfq_user')
            ->whereNotNull('data_entry_completed_at')
            ->update([
                'finalized_at' => DB::raw('data_entry_completed_at'),
                'finalized_by' => DB::raw('user_id'),
            ]);

        DB::table('rfqs')
            ->whereNotNull('data_entry_completed_at')
            ->update([
                'finalized_at' => DB::raw('data_entry_completed_at'),
                'finalized_by' => DB::raw('sourcing_completed_by'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn('finalized_at');
        });

        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn('finalized_at');
        });
    }
};
