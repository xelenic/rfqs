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
        // How many line items Data Entry had added to a part when they sent
        // it back to Sourcing (Rfq::returnSourcingPart()) — asked in the
        // Return to Sourcing popup, kept with the return's reason, and gone
        // with it once the part's assigned to Data Entry again. Null for a
        // part not sent back, or one a reviewer sent back.
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->unsignedInteger('return_line_items')->nullable()->after('return_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('return_line_items');
        });
    }
};
