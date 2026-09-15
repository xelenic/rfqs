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
            // GM Assistant adds these before forwarding to the General
            // Manager — see Rfq::recordGmAssistantDetails().
            $table->text('client_details')->nullable()->after('head_of_bd_reject_target_stage');
            $table->text('payment_terms')->nullable()->after('client_details');
            $table->foreignId('gm_assistant_completed_by')->nullable()->after('payment_terms')->constrained('users')->nullOnDelete();
            $table->timestamp('gm_assistant_completed_at')->nullable()->after('gm_assistant_completed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gm_assistant_completed_by');
            $table->dropColumn(['client_details', 'payment_terms', 'gm_assistant_completed_at']);
        });
    }
};
