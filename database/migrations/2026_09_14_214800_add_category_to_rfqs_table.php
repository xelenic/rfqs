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
            // Senior Operations' "Category Assignment" step — free text for
            // now (see Rfq::categorize()), upgradable to a fixed list later
            // with zero schema change.
            $table->string('category')->nullable()->after('priority_level');
            $table->foreignId('category_set_by')->nullable()->after('category')->constrained('users')->nullOnDelete();
            $table->timestamp('category_set_at')->nullable()->after('category_set_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_set_by');
            $table->dropColumn(['category', 'category_set_at']);
        });
    }
};
