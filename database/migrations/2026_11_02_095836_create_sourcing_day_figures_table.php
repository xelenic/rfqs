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
        // What the Sourcing Summary report needs that the workflow doesn't
        // record, typed in by hand, one row a person a day: the supplier calls
        // they say they made and missed, and how many RFQs Senior Operations
        // expected of them. Empty is "not given", not 0. See SourcingDayFigure.
        Schema::create('sourcing_day_figures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('successful_calls')->nullable();
            $table->unsignedInteger('unanswered_calls')->nullable();
            $table->unsignedInteger('unattended_calls')->nullable();
            $table->unsignedInteger('expected_rfqs')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sourcing_day_figures');
    }
};
