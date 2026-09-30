<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('rfq:clear {--force : Skip the confirmation prompt (and allow running in production)}')]
#[Description('Delete every RFQ along with its Sourcing parts, comments, and any other rfq_id-linked rows')]
class ClearRfqData extends Command
{
    use ConfirmableTrait;

    /**
     * Empties the rfqs table and every table that hangs off it — anything
     * with an rfq_id column (rfq_user, rfq_comments, ...) — leaving users,
     * roles and everything else untouched. Tables are truncated, so IDs
     * restart and the next RFQ number starts again from RFQ1001.
     */
    public function handle(): int
    {
        $tables = $this->rfqTables();

        $this->components->info('This will permanently delete all rows from: '.implode(', ', $tables));

        if (! $this->confirmToProceed('Delete ALL RFQ data?', fn (): bool => true)) {
            return self::FAILURE;
        }

        Schema::withoutForeignKeyConstraints(function () use ($tables): void {
            foreach ($tables as $table) {
                $count = DB::table($table)->count();
                DB::table($table)->truncate();
                $this->components->twoColumnDetail($table, "{$count} rows deleted");
            }
        });

        $this->components->success('All RFQ data cleared.');

        return self::SUCCESS;
    }

    /**
     * Every table holding RFQ data — the ones linked by rfq_id first, the
     * rfqs table itself last.
     *
     * @return array<int, string>
     */
    private function rfqTables(): array
    {
        $linked = collect(Schema::getTableListing(schemaQualified: false))
            ->reject(fn (string $table): bool => $table === 'rfqs')
            ->filter(fn (string $table): bool => Schema::hasColumn($table, 'rfq_id'))
            ->sort()
            ->values()
            ->all();

        return [...$linked, 'rfqs'];
    }
}
