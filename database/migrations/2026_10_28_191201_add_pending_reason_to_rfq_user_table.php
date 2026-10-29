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
        // Why a part is still pending with its Sourcing member — said by them
        // while it's on their Pending for Sourcing list, shown on hovering the
        // RFQ's Pending status. Each is also posted to the RFQ's thread; the
        // part keeps only the latest, cleared once it moves on. See
        // Rfq::recordPendingReason().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->text('pending_reason')->nullable()->after('return_line_items');
            $table->foreignId('pending_reason_by')->nullable()->after('pending_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('pending_reason_at')->nullable()->after('pending_reason_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_reason_by');
            $table->dropColumn(['pending_reason', 'pending_reason_at']);
        });
    }
};
