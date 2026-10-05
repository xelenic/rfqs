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
        // One person's line on a day's attendance sheet: present, so the time
        // tracked as theirs that day counts, or absent — and why — so it
        // doesn't. See App\Models\Attendance.
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('reason', 32)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['attendance_sheet_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
