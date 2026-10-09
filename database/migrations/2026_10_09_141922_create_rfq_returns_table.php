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
        // What each part was like just before a reviewer sent it back
        // (Rfq::rejectToStage()/rejectPartToStage()) — one row per part, a
        // whole-RFQ reject's rows sharing a batch — so whoever it went back to
        // can send it straight back to the reviewer, skipping the steps in
        // between: their earlier work is restored as it was. See
        // App\Models\RfqReturn and Rfq::forwardBack().
        Schema::create('rfq_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch')->index();
            $table->unsignedInteger('part_number');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('whole')->default(false);
            $table->string('from_stage', 32);
            $table->string('target_stage', 32);
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('part_before');
            $table->json('rfq_before');
            $table->timestamp('forwarded_at')->nullable();
            $table->foreignId('forwarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['rfq_id', 'part_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rfq_returns');
    }
};
