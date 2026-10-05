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
        Schema::table('rfq_steps', function (Blueprint $table) {
            // Whose time a stretch is — whose attendance (App\Models\Attendance)
            // decides whether it counts: Sourcing's and their Finalize the
            // part's member, Data Entry's and GM Assistant's whoever finished
            // it — unknown while it's open.
            $table->foreignId('worked_by')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
        });

        DB::table('rfq_steps')->whereIn('step', ['sourcing', 'finalize'])->update(['worked_by' => DB::raw('assignee_id')]);

        // The rest from who's on record for the part, as near as it gets.
        foreach (['data_entry' => 'data_entry_completed_by', 'gm_assistant' => 'gm_assistant_completed_by'] as $step => $column) {
            DB::table('rfq_steps')
                ->where('step', $step)
                ->whereNotNull('ended_at')
                ->update(['worked_by' => DB::raw("(select rfq_user.{$column} from rfq_user where rfq_user.rfq_id = rfq_steps.rfq_id and rfq_user.part_number = rfq_steps.part_number limit 1)")]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('worked_by');
        });
    }
};
