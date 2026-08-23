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
            // Data Entry can send a Sourcing assignee's split back for
            // rework — returned_at/return_reason record that; completing
            // it again (completed_at) clears both. See
            // Rfq::returnSourcingPartFor().
            $table->timestamp('returned_at')->nullable()->after('completed_at');
            $table->text('return_reason')->nullable()->after('returned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropColumn(['returned_at', 'return_reason']);
        });
    }
};
