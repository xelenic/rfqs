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
            $table->foreignId('sourcing_completed_by')->nullable()->after('operations_assigned_at')->constrained('users')->nullOnDelete();
            $table->timestamp('sourcing_completed_at')->nullable()->after('sourcing_completed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sourcing_completed_by');
            $table->dropColumn('sourcing_completed_at');
        });
    }
};
