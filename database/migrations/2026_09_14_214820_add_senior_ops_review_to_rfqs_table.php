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
            // Senior Operations' second review, after Data Entry — see
            // Rfq::completeSeniorOpsReview().
            $table->foreignId('senior_ops_reviewed_by')->nullable()->after('data_entry_completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('senior_ops_reviewed_at')->nullable()->after('senior_ops_reviewed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('senior_ops_reviewed_by');
            $table->dropColumn('senior_ops_reviewed_at');
        });
    }
};
