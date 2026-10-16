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
        // How many line items Data Entry has to add for the part — given by
        // its Sourcing member as they assign it to Data Entry, and given
        // again each time they do. See Rfq::completeSourcingPart().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->unsignedInteger('line_items_to_add')->nullable()->after('data_entry_assigned_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn('line_items_to_add');
        });
    }
};
