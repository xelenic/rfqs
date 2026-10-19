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
        // The line items the Head of Business Development finalized on the
        // part as they approved it on to GM Assistant — what GM Assistant's
        // target goes by (Rfq::gmAssistantLineItemsFor()). Given again each
        // time they approve it. See Rfq::approveHeadOfBdPart().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->unsignedInteger('finalized_line_items')->nullable()->after('head_of_bd_approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('finalized_line_items');
        });
    }
};
