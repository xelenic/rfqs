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
     * A comment posted along with something being done — a part marked
     * complete, sent back to Sourcing, an RFQ rejected — now records what,
     * so the thread can show it beside the author's name. The comment's own
     * text stays in body; `action` says what was done, and `meta` carries the
     * context that went with it (the part, whose it is, the stage sent back
     * to).
     */
    public function up(): void
    {
        Schema::table('rfq_comments', function (Blueprint $table) {
            $table->string('action', 40)->nullable()->after('parent_id');
            $table->json('meta')->nullable()->after('action');
        });

        $this->backfill();
    }

    /**
     * Comments from before this had the action written into their text
     * ("Marked RFQ1001-P2 of P3 complete: …"). Read it back out, so they show
     * the same way and the text isn't said twice.
     */
    private function backfill(): void
    {
        $part = '(?<label>\S+-P(?<part>\d+) of P\d+)';

        $patterns = [
            'data_entry_completed' => "/^Marked(?: {$part})? complete in Data Entry: (?<text>.*)$/s",
            'sourcing_completed' => "/^Marked(?: {$part})? complete: (?<text>.*)$/s",
            'returned_to_sourcing' => "/^Sent (?<who>.+?)'s part(?: \\({$part}\\))? back to Sourcing: (?<text>.*)$/s",
            'rejected' => '/^Head of Business Development rejected — returned to (?<stage>.+?): (?<text>.*)$/s',
        ];

        DB::table('rfq_comments')->whereNull('action')->orderBy('id')->each(function ($comment) use ($patterns) {
            foreach ($patterns as $action => $pattern) {
                if (! preg_match($pattern, $comment->body, $found)) {
                    continue;
                }

                $meta = array_filter([
                    'label' => $found['label'] ?? null,
                    'part' => isset($found['part']) && $found['part'] !== '' ? (int) $found['part'] : null,
                    'who' => $found['who'] ?? null,
                    'stage' => $found['stage'] ?? null,
                ], fn ($value) => $value !== null && $value !== '');

                DB::table('rfq_comments')->where('id', $comment->id)->update([
                    'action' => $action,
                    'body' => $found['text'],
                    'meta' => $meta === [] ? null : json_encode($meta),
                ]);

                return;
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Write the actions back into the text, as they were.
        DB::table('rfq_comments')->whereNotNull('action')->orderBy('id')->each(function ($comment) {
            $meta = json_decode($comment->meta ?? '[]', true) ?: [];
            $label = isset($meta['label']) ? " {$meta['label']}" : '';

            $body = match ($comment->action) {
                'sourcing_completed' => "Marked{$label} complete: {$comment->body}",
                'data_entry_completed' => "Marked{$label} complete in Data Entry: {$comment->body}",
                'returned_to_sourcing' => "Sent {$meta['who']}'s part".(isset($meta['label']) ? " ({$meta['label']})" : '')." back to Sourcing: {$comment->body}",
                'rejected' => "Head of Business Development rejected — returned to {$meta['stage']}: {$comment->body}",
                default => $comment->body,
            };

            DB::table('rfq_comments')->where('id', $comment->id)->update(['body' => $body]);
        });

        Schema::table('rfq_comments', function (Blueprint $table) {
            $table->dropColumn(['action', 'meta']);
        });
    }
};
