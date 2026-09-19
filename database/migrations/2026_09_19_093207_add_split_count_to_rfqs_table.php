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
            // How many Sourcing parts Operations planned this RFQ into —
            // recorded up front by the Assign Sourcing wizard, so parts can
            // exist before anyone is assigned to them. NULL on RFQs assigned
            // before the wizard existed, where the split is still just
            // "however many people are assigned". See Rfq::splitTotal().
            $table->unsignedSmallInteger('split_count')->nullable()->after('category_set_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('split_count');
        });
    }
};
