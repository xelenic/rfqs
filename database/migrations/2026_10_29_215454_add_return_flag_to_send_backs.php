<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Whether a reviewer's send-back — the RFQ's latest (reject_*) and
        // each part's own (part_rejected_*) — was a Return rather than a
        // Reject: the Head of Business Development and the General Manager can
        // do either (Rfq::RETURNABLE_STAGES). A Return lands on the receiving
        // role's Returns page; a Reject on its Rejected page. Everything sent
        // back before was a Reject, so false.
        Schema::table('rfqs', function (Blueprint $table) {
            $table->boolean('reject_is_return')->default(false)->after('reject_target_stage');
        });

        Schema::table('rfq_user', function (Blueprint $table) {
            $table->boolean('part_reject_is_return')->default(false)->after('part_reject_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('reject_is_return');
        });

        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('part_reject_is_return');
        });
    }
};
