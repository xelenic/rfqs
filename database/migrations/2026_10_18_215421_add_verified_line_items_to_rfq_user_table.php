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
        // The line items Senior Operations counted on the part as they
        // approved it on to the Head of Business Development, beside what
        // Sourcing gave to add and Data Entry added. Given again each time
        // they approve it. See Rfq::approveSeniorOpsPart().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->unsignedInteger('verified_line_items')->nullable()->after('senior_ops_reviewed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('verified_line_items');
        });
    }
};
