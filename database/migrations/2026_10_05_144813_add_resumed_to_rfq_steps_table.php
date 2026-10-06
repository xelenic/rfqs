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
        Schema::table('rfq_steps', function (Blueprint $table) {
            // Whether the stretch carries on the round before it — the RFQ was
            // put on hold (or cancelled) part way through and has since been
            // resumed — rather than starting a round of its own. See
            // App\Models\Rfq::changeStatus().
            $table->boolean('resumed')->default(false)->after('ended_by_role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_steps', function (Blueprint $table) {
            $table->dropColumn('resumed');
        });
    }
};
