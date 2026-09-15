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
            // General Manager's final approval — see Rfq::approveByGm().
            $table->foreignId('gm_approved_by')->nullable()->after('gm_assistant_completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('gm_approved_at')->nullable()->after('gm_approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gm_approved_by');
            $table->dropColumn('gm_approved_at');
        });
    }
};
