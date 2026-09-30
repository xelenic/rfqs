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
        Schema::table('rfq_user', function (Blueprint $table) {
            // A part's Sourcing member can send it back to Data Entry instead
            // of finalizing it, with a reason — shown with the part on Data
            // Entry's queue until they send it to finalize again. Always the
            // part's own member, so there's no "by". See
            // Rfq::returnToDataEntry().
            $table->timestamp('data_entry_returned_at')->nullable()->after('finalized_by');
            $table->text('data_entry_return_reason')->nullable()->after('data_entry_returned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn(['data_entry_returned_at', 'data_entry_return_reason']);
        });
    }
};
