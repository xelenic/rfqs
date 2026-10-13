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
        // How many entries Data Entry made on a part, given when they send
        // it to finalize (RfqController::completeDataEntry()) — the latest
        // round's, if it came back to them. Null for a part sent on before
        // it was asked.
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->unsignedInteger('data_entry_entries')->nullable()->after('data_entry_completed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('data_entry_entries');
        });
    }
};
