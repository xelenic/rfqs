<?php

namespace App\Models;

use Database\Factories\PrivateMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A private message from one user to another — sent from the "Message"
 * button on a person's hover card (see layouts/_user_card.blade.php), for
 * them by a comment @mentioning them on an RFQ (rfq_comment_id,
 * RfqComment::messageMentionedUsers()), or about a task given to them —
 * a part assigned to them, sent back to them (rfq_id, App\TaskMessages) —
 * and read in Messages. Only its sender and recipient can see it.
 */
class PrivateMessage extends Model
{
    /** @use HasFactory<PrivateMessageFactory> */
    use HasFactory;

    /**
     * The longest a message can be, in characters.
     */
    public const MAX_LENGTH = 2000;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'rfq_comment_id',
        'rfq_id',
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * The comment that @mentioned the recipient, or that went with the task
     * the message is about, when there was one.
     */
    public function rfqComment(): BelongsTo
    {
        return $this->belongsTo(RfqComment::class);
    }

    /**
     * The RFQ a task message is about (App\TaskMessages).
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * The RFQ this message leads back to: the one its task is about, or the
     * one its comment is on — null for a message written in Messages.
     */
    public function linkedRfq(): ?Rfq
    {
        return $this->rfq ?? $this->rfqComment?->rfq;
    }

    /**
     * Everything said between two people, in either direction.
     */
    public function scopeBetween(Builder $query, User $one, User $other): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('sender_id', $one->id)->where('recipient_id', $other->id))
            ->orWhere(fn (Builder $q) => $q->where('sender_id', $other->id)->where('recipient_id', $one->id)));
    }

    /**
     * Messages their recipient hasn't opened yet.
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * What $user reads in Messages → Private messages: what's written person
     * to person, and about a task — not the copies of an RFQ's comments, which
     * are read once, in Messages → RFQ comments (onReadableRfqsFor()), one
     * thread for the RFQ rather than one line per person they went to. A
     * comment on an RFQ $user can't open stays here: it's the only place they
     * can read it.
     */
    public function scopePersonalFor(Builder $query, User $user): void
    {
        $query->where(fn (Builder $personal) => $personal
            ->whereNull('private_messages.rfq_comment_id')
            ->orWhereNotIn('private_messages.rfq_comment_id', self::commentsReadableBy($user)));
    }

    /**
     * The copies of RFQ comments $user reads in Messages → RFQ comments —
     * those on an RFQ they can open. See personalFor().
     */
    public function scopeOnReadableRfqsFor(Builder $query, User $user): void
    {
        $query->whereIn('private_messages.rfq_comment_id', self::commentsReadableBy($user));
    }

    /**
     * The comments on RFQs $user can open (rfqs.view, Rfq::scopeVisibleTo()).
     *
     * @return Builder<RfqComment>
     */
    private static function commentsReadableBy(User $user): Builder
    {
        return RfqComment::query()->select('rfq_comments.id')
            ->when($user->can('rfqs.view'),
                fn (Builder $comments) => $comments->whereIn('rfq_comments.rfq_id', Rfq::query()->visibleTo($user)->select('rfqs.id')),
                fn (Builder $comments) => $comments->whereRaw('1 = 0'));
    }

    /**
     * How many RFQ comments are unread for $user, by RFQ — the copies sent to
     * them, on RFQs they can open, not yet read.
     *
     * @return Collection<int, int> rfq id => unread
     */
    public static function unreadCommentsByRfq(User $user): Collection
    {
        return static::query()
            ->join('rfq_comments', 'rfq_comments.id', '=', 'private_messages.rfq_comment_id')
            ->where('private_messages.recipient_id', $user->id)
            ->whereNull('private_messages.read_at')
            ->onReadableRfqsFor($user)
            ->groupBy('rfq_comments.rfq_id')
            ->selectRaw('rfq_comments.rfq_id, count(*) as total')
            ->pluck('total', 'rfq_id')
            ->map(fn ($total) => (int) $total);
    }
}
