<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A part given to another Sourcing member (Rfq::replaceSourcingPart())
        // kept the date it was first assigned, so the new member's "Assigned
        // to Sourcing" (and Assigned At) showed the first member's date. Since
        // 2026-10-30 a Replace moves it on, and its comment keeps who had the
        // part and since when (meta from_id, from_assigned_at) for the
        // timeline. Each part replaced before then is put the same way: its
        // assigned time is its latest Replace's, and each Replace's comment
        // gets the holder it came from and their assigned time.
        $replacements = DB::table('rfq_comments')
            ->where('action', 'reassigned')
            ->where('body', 'like', 'Sourcing replaced: from %')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'rfq_id', 'meta', 'body', 'created_at']);

        $byPart = $replacements->groupBy(fn (object $comment) => $comment->rfq_id.'|'.(json_decode((string) $comment->meta, true)['part'] ?? 1));

        foreach ($byPart as $key => $comments) {
            [$rfqId, $part] = explode('|', (string) $key);
            $this->moveOn($rfqId, $part, $comments);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The first assigned times aren't kept anywhere else, so there's
        // nothing to put back; the comments' extra meta is harmless.
    }

    /**
     * Moves part $part of RFQ $rfqId's assigned time on to its latest Replace
     * since it was assigned as it is now, and gives each of those Replaces'
     * comments who had the part before and since when.
     *
     * @param  Collection<int, object>  $comments
     */
    private function moveOn(string $rfqId, string $part, Collection $comments): void
    {
        $row = DB::table('rfq_user')->where('rfq_id', $rfqId)->where('part_number', $part)->first(['id', 'created_at']);

        if ($row === null || $row->created_at === null) {
            return;
        }

        // Not a Replace from before the part was assigned afresh (freed and assigned again).
        $since = $comments->filter(fn (object $comment) => CarbonImmutable::parse($comment->created_at)->greaterThan(CarbonImmutable::parse($row->created_at)));

        if ($since->isEmpty()) {
            return;
        }

        $assignedAt = $row->created_at;

        foreach ($since as $comment) {
            $meta = json_decode((string) $comment->meta, true) ?: [];

            if (! isset($meta['from_assigned_at'])) {
                DB::table('rfq_comments')->where('id', $comment->id)->update([
                    'meta' => json_encode($meta + array_filter([
                        'from_id' => $this->previousHolderOf($comment->body),
                        'from_assigned_at' => CarbonImmutable::parse($assignedAt, config('app.timezone'))->toIso8601String(),
                    ])),
                ]);
            }

            $assignedAt = $comment->created_at;
        }

        DB::table('rfq_user')->where('id', $row->id)->update(['created_at' => $assignedAt]);
    }

    /**
     * Who a Replace's comment ("Sourcing replaced: from Riley Chen to Sam
     * Rivera.") says had the part — their id, when exactly one person has
     * that name; else null, and the timeline leaves that assignment out.
     */
    private function previousHolderOf(string $body): ?int
    {
        if (! preg_match('/^Sourcing replaced: from (.+?) to /', $body, $match)) {
            return null;
        }

        $ids = DB::table('users')->where('name', $match[1])->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
};
