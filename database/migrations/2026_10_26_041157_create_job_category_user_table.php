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
        // The job categories someone in Sourcing or Data Entry takes on — their
        // tags, set as they're added or edited on the Users page. Go with
        // the category, or the person.
        Schema::create('job_category_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['job_category_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_category_user');
    }
};
