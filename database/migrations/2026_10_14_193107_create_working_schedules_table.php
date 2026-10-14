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
        // A role's own working week, or one person's, in place of the
        // company's (Setting::workingHours()) — say 07:00–16:00 for Data
        // Entry. One row per version, for a role or for a person: each counts
        // from starts_on until a later one starts, or until ended_on (the
        // first day it no longer counts) once it's been taken away, so the
        // time already counted stays counted. See App\Models\WorkingSchedule.
        Schema::create('working_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('week');
            $table->date('starts_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('working_schedules');
    }
};
