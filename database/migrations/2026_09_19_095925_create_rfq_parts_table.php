<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The planned Sourcing parts of a split RFQ ("P3 of P5") become rows of
     * their own, each with an optional person on it — so one person can hold
     * several parts, which the earlier rfq_user.part_number (one number per
     * person) couldn't express. rfq_user stays one row per person per RFQ
     * and keeps tracking that person's own completion.
     */
    public function up(): void
    {
        Schema::create('rfq_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('part_number');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['rfq_id', 'part_number']);
        });

        // Carry over anything already planned with the per-person numbers.
        DB::table('rfqs')->whereNotNull('split_count')->get(['id', 'split_count'])->each(function ($rfq) {
            $holders = DB::table('rfq_user')->where('rfq_id', $rfq->id)->whereNotNull('part_number')->pluck('user_id', 'part_number');

            foreach (range(1, $rfq->split_count) as $part) {
                DB::table('rfq_parts')->insert([
                    'rfq_id' => $rfq->id,
                    'part_number' => $part,
                    'user_id' => $holders[$part] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropUnique(['rfq_id', 'part_number']);
            $table->dropColumn('part_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->unsignedSmallInteger('part_number')->nullable()->after('user_id');
        });

        // Only one part per person fits the old column — keep each person's first.
        DB::table('rfq_parts')->whereNotNull('user_id')->orderBy('part_number')->get()->each(function ($part) {
            DB::table('rfq_user')
                ->where('rfq_id', $part->rfq_id)
                ->where('user_id', $part->user_id)
                ->whereNull('part_number')
                ->update(['part_number' => $part->part_number]);
        });

        Schema::table('rfq_user', function (Blueprint $table) {
            $table->unique(['rfq_id', 'part_number']);
        });

        Schema::dropIfExists('rfq_parts');
    }
};
