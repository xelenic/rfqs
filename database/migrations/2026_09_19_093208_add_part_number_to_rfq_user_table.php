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
        Schema::table('rfq_user', function (Blueprint $table) {
            // Which planned part ("P3 of P5") this assignee holds — see
            // rfqs.split_count. NULL on rows from before the Assign Sourcing
            // wizard existed. A part can only ever hold one person; NULLs
            // never collide with each other in the unique index.
            $table->unsignedSmallInteger('part_number')->nullable()->after('user_id');
            $table->unique(['rfq_id', 'part_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropUnique(['rfq_id', 'part_number']);
            $table->dropColumn('part_number');
        });
    }
};
