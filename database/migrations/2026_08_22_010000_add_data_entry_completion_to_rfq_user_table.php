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
            // Data Entry completes each Sourcing assignee's split
            // independently — same pattern as completed_at on the Sourcing
            // side. The RFQ as a whole only becomes Completed once every
            // assignee's split has been completed here, see
            // Rfq::completeDataEntryPartFor()/allDataEntryPartsCompleted().
            $table->timestamp('data_entry_completed_at')->nullable()->after('returned_by');
            $table->foreignId('data_entry_completed_by')->nullable()->after('data_entry_completed_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_entry_completed_by');
            $table->dropColumn('data_entry_completed_at');
        });
    }
};
