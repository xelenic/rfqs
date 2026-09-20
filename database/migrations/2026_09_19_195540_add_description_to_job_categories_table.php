<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What a category covers, shown under it in the Assign Sourcing
     * wizard's job category step so the right one is easy to pick.
     */
    public function up(): void
    {
        Schema::table('job_categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_categories', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
