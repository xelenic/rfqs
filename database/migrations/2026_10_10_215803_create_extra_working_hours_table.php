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
        // A person's own working time on top of everyone's working hours —
        // say Mon–Fri 19:00–20:00 for someone in Sourcing who works evenings.
        // Their time then counts in those hours too, from starts_on — and,
        // once it's taken away, until ended_at, so the time it counted stays
        // counted. See App\Models\ExtraWorkingHour and
        // Setting::workingPeriodsBetween().
        Schema::create('extra_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('days');
            $table->string('start', 5);
            $table->string('end', 5);
            $table->date('starts_on');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extra_working_hours');
    }
};
