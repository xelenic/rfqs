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
        // How many RFQs a Sourcing member is usually expected to finish a
        // working day, set by an Admin on their user page: the Sourcing
        // Summary's "Number of expected RFQ" on any day nobody typed one in.
        // Kept as versions, each from its starts_on, so a change never
        // rewrites past days. Empty is "none from then on". See
        // SourcingExpectedRfq.
        Schema::create('sourcing_expected_rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('expected_rfqs')->nullable();
            $table->date('starts_on');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'starts_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sourcing_expected_rfqs');
    }
};
