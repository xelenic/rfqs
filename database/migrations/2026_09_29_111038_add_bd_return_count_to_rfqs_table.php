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
            // How many times this RFQ has been sent all the way back to
            // Business Development (Rfq::rejectToStage()/
            // rejectPartToStage() targeting 'business_development') — so
            // their Returns page can tell a second or third round trip
            // apart from a first. See Rfq::rejectToStage().
            $table->unsignedInteger('bd_return_count')->default(0)->after('reject_target_stage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('bd_return_count');
        });
    }
};
