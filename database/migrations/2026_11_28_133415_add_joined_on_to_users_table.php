<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The day someone joined the company (User::$joined_on), set on the
     * Users page — what the Sourcing KPI report counts their tenure from
     * (App\PerformanceReports::sourcingKpi()). Not when their account was
     * made: that's created_at. Null until it's given.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('joined_on')->nullable()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('joined_on');
        });
    }
};
