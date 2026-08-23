<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\RfqComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RfqCommentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Anyone who can see the RFQ can discuss it — commenting isn't
            // gated behind the stricter edit/delete permissions.
            new Middleware('permission:rfqs.view', only: ['store', 'destroy']),
        ];
    }

    /**
     * Posts either a top-level comment or a reply, depending on whether
     * parent_id names a top-level comment on this same RFQ. Threads are
     * kept one level deep — replying to a reply just attaches to its
     * parent instead, rather than growing the thread further.
     *
     * Validation errors use one of two named bags ('comment' or 'reply')
     * so a failure on the reply box doesn't light up the main comment box
     * (and vice versa) — both forms post 'body' under the same name.
     */
    public function store(Request $request, Rfq $rfq): RedirectResponse
    {
        $parent = null;

        if ($parentId = $request->integer('parent_id')) {
            $parent = RfqComment::query()
                ->where('rfq_id', $rfq->id)
                ->whereNull('parent_id')
                ->find($parentId);
        }

        $validated = $request->validateWithBag($parent ? 'reply' : 'comment', [
            'body' => ['required', 'string', 'max:2000'],
        ]);

        // Via the rfq() relation so rfq_id is set automatically — comments()
        // itself is scoped to whereNull('parent_id'), which only filters
        // reads, not what gets written here.
        $rfq->comments()->create([
            'user_id' => $request->user()->id,
            'parent_id' => $parent?->id,
            'body' => $validated['body'],
        ]);

        // Comments can now be posted from more than one place — the RFQ's
        // own show page, or the quick-detail modal on Data Entry's "By
        // Sourcing" list — so send the user back wherever they came from
        // rather than always the show page.
        return redirect()->back()
            ->with('status', $parent ? 'Reply posted.' : 'Comment posted.');
    }

    /**
     * A comment (or reply) may be deleted by whoever wrote it, or by
     * anyone with rfqs.delete rights. Deleting a top-level comment takes
     * its replies with it (cascade, see the migration).
     */
    public function destroy(Request $request, Rfq $rfq, RfqComment $comment): RedirectResponse
    {
        abort_unless($comment->rfq_id === $rfq->id, 404);
        abort_unless($comment->user_id === $request->user()->id || $request->user()->can('rfqs.delete'), 403);

        $comment->delete();

        return redirect()->back()->with('status', 'Comment deleted.');
    }
}
