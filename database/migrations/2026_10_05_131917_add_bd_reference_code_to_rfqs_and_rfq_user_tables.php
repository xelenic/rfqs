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
        // The reference code Business Development has to give to close a part
        // — and the RFQ, when it closes — see Rfq::closePart() / closeOut().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->string('bd_reference_code', 100)->nullable()->after('bd_closed_by');
        });

        Schema::table('rfqs', function (Blueprint $table) {
            $table->string('bd_reference_code', 100)->nullable()->after('bd_closed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('bd_reference_code');
        });

        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('bd_reference_code');
        });
    }
};
