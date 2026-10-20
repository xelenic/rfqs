<?php

namespace App;

use App\Models\RfqCommentAttachment;
use App\Notifications\DataEntryAssigned;
use App\Notifications\DataEntryPaused;
use App\Notifications\LineItemsDiscrepancy;
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
     * Messages aren't RFQ data even with an rfq_id (a task message's link,
     * App\TaskMessages): only those about an RFQ go — see clear() — never
     * what people wrote each other.
     *
     * @var array<int, string>
     */
    private const KEPT_TABLES = ['private_messages'];

    /**
     * Every table holding RFQ data — the ones linked by rfq_id first, the
     * rfqs table itself last.
     *
     * @return array<int, string>
     */
    public static function tables(): array
    {
        $linked = collect(BackupManager::databaseTables())
            ->reject(fn (string $table): bool => $table === 'rfqs' || in_array($table, self::KEPT_TABLES, true))
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
     * "assigned to you", "paused" and "line items differ" alerts, and the
     * messages sent for mentions, about RFQs that are gone — then has every
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
        DB::table('notifications')->whereIn('type', [DataEntryAssigned::class, DataEntryPaused::class, LineItemsDiscrepancy::class])->delete();
        DB::table('private_messages')->where(fn ($messages) => $messages->whereNotNull('rfq_comment_id')->orWhereNotNull('rfq_id'))->delete();
        LiveVersion::bump();

        return $counts;
    }
}
