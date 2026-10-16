<?php

namespace App;

use App\Models\RfqCommentAttachment;
use App\Notifications\DataEntryAssigned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Clears every RFQ and everything that hangs off one — anything with an
 * rfq_id column (parts, comments and their files, returns, steps) — leaving
 * users, roles, settings and everything else untouched. Tables are
 * truncated, so IDs restart, and the next RFQ number is where Settings →
 * RFQ Numbers says (Rfq::nextRfqNumber()). Used by `php artisan rfq:clear`
 * and Settings → Danger Zone.
 */
final class RfqDataEraser
{
    /**
     * Every table holding RFQ data — the ones linked by rfq_id first, the
     * rfqs table itself last.
     *
     * @return array<int, string>
     */
    public static function tables(): array
    {
        $linked = collect(Schema::getTableListing(schemaQualified: false))
            ->reject(fn (string $table): bool => $table === 'rfqs')
            ->filter(fn (string $table): bool => Schema::hasColumn($table, 'rfq_id'))
            ->sort()
            ->values()
            ->all();

        return [...$linked, 'rfqs'];
    }

    /**
     * How many rows each of those tables holds now.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return collect(self::tables())->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    /**
     * Empties them — and the photos and files attached to comments, and the
     * "assigned to you" alerts about parts that are gone — then has every
     * open page refresh (LiveVersion: a truncate isn't a write it watches).
     *
     * @return array<string, int> how many rows each table had
     */
    public static function clear(): array
    {
        $counts = self::counts();

        Schema::withoutForeignKeyConstraints(function (): void {
            foreach (self::tables() as $table) {
                DB::table($table)->truncate();
            }
        });

        Storage::disk(RfqCommentAttachment::DISK)->deleteDirectory('rfq-attachments');
        DB::table('notifications')->where('type', DataEntryAssigned::class)->delete();
        LiveVersion::bump();

        return $counts;
    }
}
