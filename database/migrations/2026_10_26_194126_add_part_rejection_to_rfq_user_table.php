<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The review and closing stages whose Reject & Return is a rejection
     * (Rfq::REJECTABLE_STAGES, as it stands today).
     *
     * @var array<int, string>
     */
    private const REVIEW_STAGES = ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review', 'bd_closing'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The part's own record of the last time a reviewer rejected it back
        // to an earlier step — where to, from which review, by whom, when and
        // why — what puts it on that step's Rejected page until it's sent on.
        // The RFQ's own reject_* columns hold only its latest rejection; a
        // split's parts can each be rejected to a different step. Set by
        // Rfq::rejectToStage()/rejectPartToStage(), cleared by a co-worker's
        // send-back (Rfq::returnSourcingPart(), returnToDataEntry()).
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->string('part_rejected_to', 32)->nullable()->after('return_line_items');
            $table->string('part_rejected_from', 32)->nullable()->after('part_rejected_to');
            $table->foreignId('part_rejected_by')->nullable()->after('part_rejected_from')->constrained('users')->nullOnDelete();
            $table->timestamp('part_rejected_at')->nullable()->after('part_rejected_by');
            $table->text('part_reject_reason')->nullable()->after('part_rejected_at');
        });

        $this->recordRejectionsStillWaiting();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_rejected_by');
            $table->dropColumn(['part_rejected_to', 'part_rejected_from', 'part_rejected_at', 'part_reject_reason']);
        });
    }

    /**
     * Each part a reviewer rejected back to Sourcing, Data Entry or the
     * General Manager before these columns existed, and still waiting there,
     * gets its record: the part's latest rejection (rfq_returns) not sent
     * straight back, while the part is still where it was sent — and the
     * reason from the rejection's comment.
     */
    private function recordRejectionsStillWaiting(): void
    {
        $latest = DB::table('rfq_returns')
            ->whereIn('from_stage', self::REVIEW_STAGES)
            ->whereIn('target_stage', ['sourcing', 'data_entry', 'gm_review'])
            ->whereNull('forwarded_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (object $return) => $return->rfq_id.'-'.$return->part_number);

        foreach ($latest as $return) {
            $part = DB::table('rfq_user')->where('rfq_id', $return->rfq_id)->where('part_number', $return->part_number)->first();

            if (! $part || ! $this->stillWaiting($part, $return)) {
                continue;
            }

            DB::table('rfq_user')->where('id', $part->id)->update([
                'part_rejected_to' => $return->target_stage,
                'part_rejected_from' => $return->from_stage,
                'part_rejected_by' => $return->returned_by,
                'part_rejected_at' => $return->created_at,
                'part_reject_reason' => $this->reasonFor($return),
            ]);
        }
    }

    /**
     * Why $return was made: the 'rejected' comment its reviewer posted with
     * it, moments after — the one naming its part, of a split — the nearest
     * in time.
     */
    private function reasonFor(object $return): ?string
    {
        $at = CarbonImmutable::parse($return->created_at);

        $comments = DB::table('rfq_comments')
            ->where('rfq_id', $return->rfq_id)
            ->where('action', 'rejected')
            ->where('user_id', $return->returned_by)
            ->whereBetween('created_at', [$at->subMinute(), $at->addMinute()])
            ->get()
            ->filter(fn (object $comment) => ! isset(json_decode((string) $comment->meta, true)['part'])
                || (int) json_decode((string) $comment->meta, true)['part'] === (int) $return->part_number);

        return $comments
            ->sortBy(fn (object $comment) => abs(CarbonImmutable::parse($comment->created_at)->diffInSeconds($at)))
            ->first()?->body;
    }

    /**
     * Whether $part is still where $return sent it, with nothing sent back
     * since: back with Sourcing from that very return, still with Data
     * Entry with no Sourcing return after it, or still with the General
     * Manager.
     */
    private function stillWaiting(object $part, object $return): bool
    {
        if ($part->status !== null) {
            return false;
        }

        $at = CarbonImmutable::parse($return->created_at);

        return match ($return->target_stage) {
            'sourcing' => $part->completed_at === null && $part->returned_at !== null
                && abs(CarbonImmutable::parse($part->returned_at)->diffInSeconds($at)) <= 120,
            'data_entry' => $part->completed_at !== null && $part->data_entry_completed_at === null
                && ($part->data_entry_returned_at === null || CarbonImmutable::parse($part->data_entry_returned_at)->lessThan($at)),
            'gm_review' => $part->gm_assistant_completed_at !== null && $part->gm_approved_at === null,
            default => false,
        };
    }
};
