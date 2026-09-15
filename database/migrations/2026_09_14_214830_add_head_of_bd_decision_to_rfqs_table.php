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
        Schema::table('rfqs', function (Blueprint $table) {
            // Head of Business Development's approve/reject-to-any-stage
            // decision — see Rfq::approveByHeadOfBd() / rejectToStage().
            $table->foreignId('head_of_bd_approved_by')->nullable()->after('senior_ops_reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('head_of_bd_approved_at')->nullable()->after('head_of_bd_approved_by');
            $table->foreignId('head_of_bd_rejected_by')->nullable()->after('head_of_bd_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('head_of_bd_rejected_at')->nullable()->after('head_of_bd_rejected_by');
            $table->text('head_of_bd_reject_reason')->nullable()->after('head_of_bd_rejected_at');
            $table->string('head_of_bd_reject_target_stage')->nullable()->after('head_of_bd_reject_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_of_bd_approved_by');
            $table->dropConstrainedForeignId('head_of_bd_rejected_by');
            $table->dropColumn(['head_of_bd_approved_at', 'head_of_bd_rejected_at', 'head_of_bd_reject_reason', 'head_of_bd_reject_target_stage']);
        });
    }
};
