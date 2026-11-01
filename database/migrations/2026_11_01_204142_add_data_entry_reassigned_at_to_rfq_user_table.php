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
        // When Senior Operations last gave a part with Data Entry to someone
        // else there (Rfq::replaceDataEntryPart()): what the new person's idle
        // alert counts from, and the date beside "Reassigned to" on their list.
        // Cleared when Sourcing hands the part over afresh.
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->timestamp('data_entry_reassigned_at')->nullable()->after('data_entry_assigned_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('data_entry_reassigned_at');
        });
    }
};
