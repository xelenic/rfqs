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
        // When, and by whom, Business Development fixed an RFQ sent back to
        // them (Rfq::resolveBusinessDevelopmentReturn()) — what keeps it on
        // Senior Operations' Returns page, and off Unassigned, till every
        // part is assigned again (Rfq::assignSourcingParts()).
        Schema::table('rfqs', function (Blueprint $table) {
            $table->timestamp('bd_fixed_at')->nullable()->after('bd_return_count');
            $table->foreignId('bd_fixed_by')->nullable()->after('bd_fixed_at')->constrained('users')->nullOnDelete();
        });

        $this->markThoseAlreadyFixed();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bd_fixed_by');
            $table->dropColumn('bd_fixed_at');
        });
    }

    /**
     * Each RFQ Business Development fixed before these columns existed, and
     * still waiting on Senior Operations to assign it: open, nothing sent
     * back on record, a planned part nobody holds, and its latest send-back
     * one to Business Development (its 'rejected' comment). When it was
     * fixed is its last update; who, unknown.
     */
    private function markThoseAlreadyFixed(): void
    {
        DB::table('rfqs')
            ->where('status', 'Pending')
            ->whereNull('reject_target_stage')
            ->where('bd_return_count', '>', 0)
            ->where(fn ($rfqs) => $rfqs
                ->whereNotExists(fn ($parts) => $parts->from('rfq_user')->whereColumn('rfq_user.rfq_id', 'rfqs.id'))
                ->orWhereRaw('rfqs.split_count > (select count(*) from rfq_user where rfq_user.rfq_id = rfqs.id)'))
            ->get(['id', 'updated_at'])
            ->each(function (object $rfq) {
                $latestSendBack = DB::table('rfq_comments')
                    ->where('rfq_id', $rfq->id)
                    ->where('action', 'rejected')
                    ->orderByDesc('id')
                    ->value('meta');

                if ((json_decode((string) $latestSendBack, true)['stage'] ?? null) === 'Business Development') {
                    DB::table('rfqs')->where('id', $rfq->id)->update(['bd_fixed_at' => $rfq->updated_at]);
                }
            });
    }
};
