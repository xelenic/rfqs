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
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('operations_assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('operations_assigned_at')->nullable();
            $table->string('wc_number');
            $table->string('rfq_number');
            $table->string('priority_level')->default('Medium');
            $table->string('status')->default('Pending');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};
