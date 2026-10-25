<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Messages → RFQ comments: the discussion on each RFQ the signed-in user is
 * part of, one thread per RFQ — those they've been sent a comment on
 * (RfqComment::messageMentionedUsers(), messagePeopleInvolved()) or have
 * commented on themselves — the latest first, with how many of its comments
 * are still unread for them (their comment messages,
 * private_messages.rfq_comment_id). Opening one reads them; a comment
 * posted there goes on the RFQ (RfqCommentController::store()) and comes
 * back here. Business Development, whose RFQ page keeps the thread blurred,
 * reads only the comments they wrote or were sent, and posts none here.
 */
class RfqDiscussionController extends Controller
{
    /**
     * How many RFQs the list shows at once.
     */
    private const PER_PAGE = 20;

    /**
     * The RFQs discussed with the signed-in user, latest comment first, each
     * with its latest comment, how many comments it has and how many are
     * unread for them.
     */
    public function index(Request $request): View
    {
        $me = $request->user();
        $theirs = fn (Builder $comments) => self::discussionFor($comments, $me);

        $rfqs = $me->can('rfqs.view')
            ? Rfq::query()
                ->whereIn('rfqs.id', RfqComment::query()->select('rfq_id')->where(fn (Builder $part) => $part
                    ->where('user_id', $me->id)
                    ->orWhereIn('id', self::sentTo($me))))
                ->visibleTo($me)
                ->addSelect([
                    'last_comment_id' => RfqComment::query()->select('id')->whereColumn('rfq_comments.rfq_id', 'rfqs.id')->tap($theirs)->latest('id')->limit(1),
                    'comment_total' => RfqComment::query()->selectRaw('count(*)')->whereColumn('rfq_comments.rfq_id', 'rfqs.id')->tap($theirs),
                ])
                ->orderByDesc('last_comment_id')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
            : new LengthAwarePaginator([], 0, self::PER_PAGE);

        return view('admin.messages.rfqs', [
            'rfqs' => $rfqs,
            'lastComments' => RfqComment::query()->with('author')->whereIn('id', collect($rfqs->items())->pluck('last_comment_id'))->get()->keyBy('id'),
            'unread' => self::unreadByRfq($me),
        ]);
    }

    /**
     * One RFQ's comments and replies, oldest first, with the box to add one.
     * Opening it reads the user's comment messages about it — only when
     * there are any: a write on every visit would keep a live page
     * refreshing itself.
     */
    public function show(Request $request, Rfq $rfq): View
    {
        $me = $request->user();

        abort_unless($me->can('rfqs.view'), 403, 'Only people who can see RFQs can read their comments.');

        $unread = $me->receivedMessages()->unread()->whereIn('rfq_comment_id', RfqComment::query()->select('id')->where('rfq_id', $rfq->id));

        if ($unread->exists()) {
            $unread->update(['read_at' => now()]);
        }

        return view('admin.messages.rfq', [
            'rfq' => $rfq,
            'comments' => RfqComment::query()
                ->where('rfq_id', $rfq->id)
                ->tap(fn (Builder $comments) => self::discussionFor($comments, $me))
                ->with(['author.roles', 'attachments', 'parent.author'])
                ->oldest()
                ->oldest('id')
                ->get(),
            'canPost' => ! self::readsOnlyTheirOwn($me),
        ]);
    }

    /**
     * How many comments are unread for $user, by RFQ — their comment
     * messages not yet opened.
     *
     * @return Collection<int, int> rfq id => unread
     */
    public static function unreadByRfq(User $user): Collection
    {
        return PrivateMessage::query()
            ->join('rfq_comments', 'rfq_comments.id', '=', 'private_messages.rfq_comment_id')
            ->where('private_messages.recipient_id', $user->id)
            ->whereNull('private_messages.read_at')
            ->groupBy('rfq_comments.rfq_id')
            ->selectRaw('rfq_comments.rfq_id, count(*) as total')
            ->pluck('total', 'rfq_id')
            ->map(fn ($total) => (int) $total);
    }

    /**
     * Narrows $comments to the discussion $user reads: comments and replies,
     * not workflow actions — for Business Development, only those they wrote
     * or were sent.
     *
     * @param  Builder<RfqComment>  $comments
     */
    private static function discussionFor(Builder $comments, User $user): void
    {
        $comments->whereNull('rfq_comments.action')
            ->when(self::readsOnlyTheirOwn($user), fn (Builder $theirs) => $theirs->where(fn (Builder $mine) => $mine
                ->where('rfq_comments.user_id', $user->id)
                ->orWhereIn('rfq_comments.id', self::sentTo($user))));
    }

    /**
     * The comments $user has been sent in their Messages.
     *
     * @return Builder<PrivateMessage>
     */
    private static function sentTo(User $user): Builder
    {
        return PrivateMessage::query()->select('rfq_comment_id')->where('recipient_id', $user->id)->whereNotNull('rfq_comment_id');
    }

    /**
     * Whether $user reads only the comments they wrote or were sent —
     * Business Development, as on the RFQ page (show.blade.php's
     * $restrictAssignment).
     */
    private static function readsOnlyTheirOwn(User $user): bool
    {
        return $user->hasRole('Business Development');
    }
}
