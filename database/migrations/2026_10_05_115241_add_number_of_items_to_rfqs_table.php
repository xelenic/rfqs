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
            // How many items the RFQ asks for — given when it's created. RFQs
            // from before it was asked have none until they're next edited.
            $table->unsignedInteger('number_of_items')->nullable()->after('priority_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('number_of_items');
        });
    }
};
