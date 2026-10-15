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
        // Who in Data Entry the part's Sourcing member gave it to when they
        // assigned it to Data Entry — only they see it, start it and are told
        // of it. Null: anyone in Data Entry, whoever starts it first. See
        // Rfq::completeSourcingPart().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->foreignId('data_entry_assigned_to')->nullable()->after('data_entry_started_by')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_entry_assigned_to');
        });
    }
};
