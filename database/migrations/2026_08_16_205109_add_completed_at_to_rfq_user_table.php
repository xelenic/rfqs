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
            // Each Sourcing assignee completes their own split of the work
            // independently — the RFQ as a whole only hands off to Data
            // Entry once every assignee here has completed_at set. See
            // RfqController::completeSourcing().
            $table->timestamp('completed_at')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
