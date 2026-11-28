<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a reviewer's send-back (RfqReturn) was a Return — sent back for
     * changes, onto the receiving role's Returns page — rather than a Reject
     * (Rfq::RETURNABLE_STAGES). The Sourcing KPI report counts only the
     * Rejects. Those made before are told apart by their thread: a Return's
     * 'rejected' comment says returned, and is posted with the send-back.
     */
    public function up(): void
    {
        Schema::table('rfq_returns', function (Blueprint $table) {
            $table->boolean('is_return')->default(false)->after('target_stage');
        });

        DB::table('rfq_comments')
            ->where('action', 'rejected')
            ->where('meta', 'like', '%"returned":true%')
            ->get(['rfq_id', 'created_at'])
            ->each(function (object $comment) {
                $at = CarbonImmutable::parse($comment->created_at);

                DB::table('rfq_returns')
                    ->where('rfq_id', $comment->rfq_id)
                    ->whereBetween('created_at', [$at->subSeconds(10)->toDateTimeString(), $at->toDateTimeString()])
                    ->update(['is_return' => true]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_returns', function (Blueprint $table) {
            $table->dropColumn('is_return');
        });
    }
};
