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
        // When Data Entry paused the part they'd started: its time stops
        // counting, and they're free to start another, till they resume it.
        // Null while it's running (or not started). See Rfq::pauseDataEntryPart().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->timestamp('data_entry_paused_at')->nullable()->after('data_entry_started_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('data_entry_paused_at');
        });
    }
};
