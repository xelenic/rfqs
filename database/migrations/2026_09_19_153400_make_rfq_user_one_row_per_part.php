<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The columns that track one assignment's own progress — copied when a
     * person's single assignment is split into one row per part they hold.
     *
     * @var array<int, string>
     */
    private const STATE_COLUMNS = [
        'completed_at', 'returned_at', 'return_reason', 'returned_by',
        'data_entry_completed_at', 'data_entry_completed_by',
    ];

    /**
     * Run the migrations.
     *
     * A person holding several parts of a split RFQ now has one rfq_user
     * row per part, each with its own completed / returned / Data Entry
     * state — so each part shows up and is completed on its own, instead of
     * a person's parts being lumped into one shared assignment. That
     * replaces rfq_parts (which only recorded who held which part).
     */
    public function up(): void
    {
        // An earlier migration (2026_09_19_093208) already adds this column
        // and its own unique index on some environments — guard both so this
        // migration is safe to run regardless of which state it starts from.
        if (! Schema::hasColumn('rfq_user', 'part_number')) {
            Schema::table('rfq_user', function (Blueprint $table) {
                $table->unsignedSmallInteger('part_number')->nullable()->after('user_id');
            });
        }

        // Create the new unique index before dropping the old one — MySQL
        // refuses to drop (rfq_id, user_id) while it's the only index backing
        // the rfq_id foreign key. Safe even before part_number is backfilled,
        // since MySQL treats each NULL in a unique index as distinct.
        if (! $this->indexExists('rfq_user', 'rfq_user_rfq_id_part_number_unique')) {
            Schema::table('rfq_user', function (Blueprint $table) {
                $table->unique(['rfq_id', 'part_number']);
            });
        }

        if ($this->indexExists('rfq_user', 'rfq_user_rfq_id_user_id_unique')) {
            Schema::table('rfq_user', function (Blueprint $table) {
                $table->dropUnique(['rfq_id', 'user_id']);
            });
        }

        // RFQs whose split was planned: number each held part's row. When
        // one person held several parts, their first row takes the first
        // part and the rest are new rows starting in the same state.
        DB::table('rfq_parts')->whereNotNull('user_id')->orderBy('rfq_id')->orderBy('part_number')->get()
            ->each(function ($part) {
                $rows = DB::table('rfq_user')->where('rfq_id', $part->rfq_id)->where('user_id', $part->user_id)->orderBy('id')->get();
                $unnumbered = $rows->firstWhere('part_number', null);

                if ($unnumbered) {
                    DB::table('rfq_user')->where('id', $unnumbered->id)->update(['part_number' => $part->part_number]);

                    return;
                }

                $first = $rows->first();

                DB::table('rfq_user')->insert([
                    'rfq_id' => $part->rfq_id,
                    'user_id' => $part->user_id,
                    'part_number' => $part->part_number,
                    'created_at' => $first->created_at,
                    'updated_at' => $first->updated_at,
                ] + collect(self::STATE_COLUMNS)->mapWithKeys(fn (string $column) => [$column => $first->{$column}])->all());
            });

        // Anything still unnumbered was assigned before parts were planned
        // up front (one part per assignee, in assignment order), or is left
        // over from an inconsistent split — number it after whatever's taken
        // and make sure the RFQ's split covers it.
        DB::table('rfq_user')->whereNull('part_number')->orderBy('id')->get()->groupBy('rfq_id')
            ->each(function ($rows, $rfqId) {
                $next = (int) DB::table('rfq_user')->where('rfq_id', $rfqId)->max('part_number') + 1;

                foreach ($rows as $row) {
                    DB::table('rfq_user')->where('id', $row->id)->update(['part_number' => $next++]);
                }

                DB::table('rfqs')->where('id', $rfqId)->update([
                    'split_count' => max((int) DB::table('rfqs')->where('id', $rfqId)->value('split_count'), $next - 1),
                ]);
            });

        Schema::dropIfExists('rfq_parts');
    }

    /**
     * Whether the given index already exists on the given table.
     */
    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('rfq_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('part_number');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['rfq_id', 'part_number']);
        });

        DB::table('rfqs')->whereNotNull('split_count')->get(['id', 'split_count'])->each(function ($rfq) {
            $holders = DB::table('rfq_user')->where('rfq_id', $rfq->id)->pluck('user_id', 'part_number');

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

        // Back to one row per person: keep each person's first (lowest part).
        DB::table('rfq_user')->orderBy('rfq_id')->orderBy('user_id')->orderBy('part_number')->get()
            ->groupBy(fn ($row) => $row->rfq_id.'-'.$row->user_id)
            ->each(fn ($rows) => DB::table('rfq_user')->whereIn('id', $rows->skip(1)->pluck('id'))->delete());

        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropUnique(['rfq_id', 'part_number']);
            $table->dropColumn('part_number');
            $table->unique(['rfq_id', 'user_id']);
        });
    }
};
