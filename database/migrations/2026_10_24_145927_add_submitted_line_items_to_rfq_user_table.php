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
        // The line items the Sourcing member submitted on the part as they
        // finalized it on to Senior Operations' review, beside what they gave
        // to add and Data Entry added. Given again each time they finalize
        // it. See Rfq::finalizePart().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->unsignedInteger('submitted_line_items')->nullable()->after('finalized_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('submitted_line_items');
        });
    }
};
