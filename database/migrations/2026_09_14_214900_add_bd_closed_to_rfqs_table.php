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
            // Business Development's final closing action — see
            // Rfq::closeOut().
            $table->foreignId('bd_closed_by')->nullable()->after('gm_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('bd_closed_at')->nullable()->after('bd_closed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bd_closed_by');
            $table->dropColumn('bd_closed_at');
        });
    }
};
