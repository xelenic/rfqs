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
        // Why Data Entry paused the part, and the document it's waiting on,
        // if any — asked when they pause, shown while it stays paused. Each
        // pause is also posted to the RFQ's thread. See Rfq::pauseDataEntryPart().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->text('data_entry_pause_reason')->nullable()->after('data_entry_paused_at');
            $table->string('data_entry_pause_reference')->nullable()->after('data_entry_pause_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn(['data_entry_pause_reason', 'data_entry_pause_reference']);
        });
    }
};
