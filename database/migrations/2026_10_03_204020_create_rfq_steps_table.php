<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Every stretch a part spent at a timed step — Sourcing's work, Data
        // Entry's, Sourcing's Finalize, GM Assistant's — one row per round, so
        // time spent adds up across rework. An open row (no ended_at) is the
        // step the part is at now. See App\Models\RfqStep, Rfq::syncSteps().
        Schema::create('rfq_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('part_number');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('step', 32);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['rfq_id', 'ended_at']);
        });

        $this->backfill();
    }

    /**
     * RFQs from before the log: one round of each step a part has been
     * through, from the times already saved on it — the rounds before a
     * return weren't kept, so only the latest one is. Still-open steps only
     * on Pending RFQs.
     */
    private function backfill(): void
    {
        $parts = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->select('rfq_user.*', 'rfqs.status as rfq_status')
            ->get();

        $rows = [];

        foreach ($parts as $part) {
            $isOpen = $part->rfq_status === 'Pending';
            $add = function (string $step, ?string $startedAt, ?string $endedAt) use (&$rows, $part, $isOpen) {
                if ($startedAt === null || ($endedAt === null && ! $isOpen)) {
                    return;
                }

                $rows[] = [
                    'rfq_id' => $part->rfq_id,
                    'part_number' => $part->part_number,
                    'assignee_id' => $part->user_id,
                    'step' => $step,
                    'started_at' => $startedAt,
                    'ended_at' => $endedAt,
                ];
            };

            $add('sourcing', $part->returned_at ?? $part->created_at, $part->completed_at);

            if ($part->completed_at !== null) {
                $add('data_entry', $part->data_entry_returned_at ?? $part->completed_at, $part->data_entry_completed_at);
            }

            if ($part->data_entry_completed_at !== null) {
                $add('finalize', $part->data_entry_completed_at, $part->finalized_at);
            }

            if ($part->head_of_bd_approved_at !== null) {
                $add('gm_assistant', $part->head_of_bd_approved_at, $part->gm_assistant_completed_at);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('rfq_steps')->insert($chunk);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rfq_steps');
    }
};
