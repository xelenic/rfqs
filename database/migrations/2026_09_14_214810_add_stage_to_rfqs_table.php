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
            // The post-Data-Entry pipeline position — Senior Operations 2nd
            // review, Head of BD, GM Assistant, GM, BD closing, closed. Null
            // until completeDataEntryPartFor() finishes the last Sourcing
            // split; before that the existing pre-Data-Entry pipeline
            // (operations_assigned_at, the assignees pivot, sourcing_/
            // data_entry_completed_at) already encodes position on its own.
            // See Rfq::STAGES / Rfq::stageLabel().
            $table->string('stage')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
    }
};
