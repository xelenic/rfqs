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
            // Data Entry formally closing the RFQ out (status = Completed)
            // once they've finished processing it — see
            // Rfq::completeByDataEntry().
            $table->foreignId('data_entry_completed_by')->nullable()->after('sourcing_completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('data_entry_completed_at')->nullable()->after('data_entry_completed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_entry_completed_by');
            $table->dropColumn('data_entry_completed_at');
        });
    }
};
