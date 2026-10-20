<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class RfqComment extends Model
{
    /**
     * What a comment posted along with an action records, shown as a chip
     * beside the author's name: how it reads, its Bootstrap icon, and its tone
     * (success or danger — the colour of its marker on the thread).
     *
     * @var array<string, array{label: string, icon: string, tone: string}>
     */
    public const ACTIONS = [
        'sourcing_completed' => ['label' => 'Assigned to Data Entry', 'icon' => 'bi-check-circle-fill', 'tone' => 'success'],
        'data_entry_completed' => ['label' => 'Sent to Finalize', 'icon' => 'bi-send-check', 'tone' => 'success'],
        'returned_to_sourcing' => ['label' => 'Returned to Sourcing', 'icon' => 'bi-arrow-counterclockwise', 'tone' => 'danger'],
        'returned_to_data_entry' => ['label' => 'Returned to Data Entry', 'icon' => 'bi-arrow-counterclockwise', 'tone' => 'danger'],
        'rejected' => ['label' => 'Rejected', 'icon' => 'bi-x-octagon-fill', 'tone' => 'danger'],
        'put_on_hold' => ['label' => 'Put on hold', 'icon' => 'bi-pause-circle-fill', 'tone' => 'danger'],
        'cancelled' => ['label' => 'Cancelled', 'icon' => 'bi-x-circle-fill', 'tone' => 'danger'],
        'resumed' => ['label' => 'Resumed', 'icon' => 'bi-play-circle-fill', 'tone' => 'success'],
        'reopened' => ['label' => 'Reopened', 'icon' => 'bi-arrow-repeat', 'tone' => 'success'],
        'reassigned' => ['label' => 'Reassigned', 'icon' => 'bi-person-gear', 'tone' => 'info'],
        'forwarded_back' => ['label' => 'Sent straight back', 'icon' => 'bi-skip-forward-fill', 'tone' => 'success'],
        'gm_assistant_submitted' => ['label' => 'Submitted to General Manager', 'icon' => 'bi-send-check', 'tone' => 'success'],
        'data_entry_paused' => ['label' => 'Paused', 'icon' => 'bi-pause-circle-fill', 'tone' => 'info'],
        'gm_assistant_paused' => ['label' => 'Paused', 'icon' => 'bi-pause-circle-fill', 'tone' => 'info'],
    ];

    protected $fillable = [
        'user_id',
        'parent_id',
        'body',
        'action',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * How this comment's action reads ("Marked complete"), or null for an
     * ordinary comment.
     */
    public function actionLabel(): ?string
    {
        return self::ACTIONS[$this->action]['label'] ?? null;
    }

    /**
     * The icon for this comment's marker on the thread — the action's, or a
     * speech bubble for an ordinary comment.
     */
    public function actionIcon(): string
    {
        return self::ACTIONS[$this->action]['icon'] ?? 'bi-chat-left-text';
    }

    /**
     * The tone of its marker: the action's, or the primary blue.
     */
    public function actionTone(): string
    {
        return self::ACTIONS[$this->action]['tone'] ?? 'primary';
    }

    /**
     * What the action was about, for the small chip after it — whose part,
     * which one, where it was sent back to, or how many line items Data Entry
     * added ("Sam Rivera's part · RFQ1001-P2 of P3 · 12 line items added", "Returned
     * to Sourcing") — or null when there's nothing to add.
     */
    public function actionContext(): ?string
    {
        $meta = $this->meta ?? [];

        $context = collect([
            isset($meta['who']) ? "{$meta['who']}'s part" : null,
            $meta['label'] ?? null,
            isset($meta['assigned_to']) ? "to {$meta['assigned_to']}" : null,
            isset($meta['line_items']) ? trans_choice('1 line item to add|:count line items to add', $meta['line_items'], ['count' => $meta['line_items']]) : null,
            isset($meta['stage']) ? "Returned to {$meta['stage']}" : null,
            isset($meta['entries']) ? trans_choice('1 line item added|:count line items added', $meta['entries'], ['count' => $meta['entries']]) : null,
            isset($meta['entries'], $meta['to_add']) ? RfqAssignment::lineItemsDiscrepancyText($meta['entries'], $meta['to_add']) : null,
            isset($meta['reference']) ? "Ref {$meta['reference']}" : null,
            ! empty($meta['customer_clarification']) ? 'Waiting for customer clarification' : null,
        ])->filter()->implode(' · ');

        return $context !== '' ? $context : null;
    }

    /**
     * Whether a Sourcing or Data Entry viewer sees it (Rfq::activityTimelineFor(),
     * User::seesOnlySourcingAndDataEntryTimeline()): an ordinary comment or
     * reply — the discussion is everyone's — or an action someone in Sourcing
     * or Data Entry took. Not another role's action: a review, a rejection, a
     * hold, a reassignment, GM Assistant's submit.
     */
    public function isForSourcingAndDataEntry(): bool
    {
        return $this->action === null || $this->author?->isSourcingOrDataEntry() === true;
    }

    /**
     * Whether this comment belongs on $part's thread of a split RFQ. One
     * recorded against a part — its completion, its return — is for that part
     * alone; an ordinary comment, or an action on the RFQ as a whole (a
     * rejection), is for every part.
     */
    public function concernsPart(int $part): bool
    {
        $commentPart = $this->meta['part'] ?? null;

        return $commentPart === null || (int) $commentPart === $part;
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * Who wrote the comment. May be null if their account was later deleted.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The top-level comment this one replies to, if it's a reply.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    protected static function booted(): void
    {
        // Its files, and its replies', go with it — the rows themselves go by
        // the database's cascade, which leaves the files behind.
        static::deleting(function (self $comment) {
            Storage::disk(RfqCommentAttachment::DISK)->delete(RfqCommentAttachment::query()
                ->whereIn('rfq_comment_id', $comment->replies()->pluck('id')->push($comment->id))
                ->pluck('path')
                ->all());
        });
    }

    /**
     * The photos and files attached to it.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(RfqCommentAttachment::class)->oldest('id');
    }

    /**
     * Keeps $files on the private disk, attached to this comment.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function storeAttachments(array $files): void
    {
        foreach ($files as $file) {
            $this->attachments()->create([
                'rfq_id' => $this->rfq_id,
                'path' => $file->store('rfq-attachments/'.$this->rfq_id, RfqCommentAttachment::DISK),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }
    }

    /**
     * Runs $action — something that posts to $rfq's thread: a comment, or the
     * reason an action gives — and attaches $files to what it posted. Where it
     * posts more than one, the last: a reject sending parts back to Sourcing
     * posts each part's return first, then its own reason.
     *
     * @template TResult
     *
     * @param  array<int, UploadedFile>  $files
     * @param  Closure(): TResult  $action
     * @return TResult
     */
    public static function attachingFiles(Rfq $rfq, array $files, Closure $action): mixed
    {
        if ($files === []) {
            return $action();
        }

        $before = static::query()->where('rfq_id', $rfq->id)->max('id') ?? 0;
        $result = $action();

        static::query()->where('rfq_id', $rfq->id)->where('id', '>', $before)->latest('id')->first()?->storeAttachments($files);

        return $result;
    }

    /**
     * Replies to this comment — threads are kept one level deep, so a
     * reply's own replies() collection is always empty. Deleting a comment
     * cascades to its replies (see the migration).
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest()->oldest('id');
    }

    /**
     * The comment body, HTML-escaped, with any "@Full Name" mentions of a
     * known mentionable user (mentionableUsers() — see the @mention dropdown
     * in admin.js) wrapped in a highlight span: whole names only, the longest
     * first, so "@Sam Rivera" is one mention, not Sam's inside it. Renders raw
     * HTML, so callers must use {!! !!}, never {{ }}.
     */
    public function bodyWithMentions(Collection $mentionableUsers): string
    {
        $escaped = e($this->body);

        $names = $mentionableUsers->pluck('name')->filter()->unique()
            ->sortByDesc(fn (string $name) => mb_strlen($name))
            ->map(fn (string $name) => preg_quote(e($name), '/'));

        if ($names->isEmpty()) {
            return $escaped;
        }

        return preg_replace_callback(
            '/@(?:'.$names->implode('|').')(?![\p{L}\p{N}])/u',
            fn (array $match) => '<span class="mention-highlight">'.$match[0].'</span>',
            $escaped,
        ) ?? $escaped;
    }

    /**
     * Everyone who can be @mentioned in a comment: all the active staff, as
     * the @mention dropdown on the RFQ's page lists them (mentionDirectory()),
     * by name. Looked up once a request — kept on the request — since every
     * comment on a page asks.
     *
     * @return Collection<int, User>
     */
    public static function mentionableUsers(): Collection
    {
        $request = request();

        if (! $request->attributes->has('mentionable_users')) {
            $request->attributes->set('mentionable_users', User::query()->active()->with('roles')->orderBy('name')->get());
        }

        return $request->attributes->get('mentionable_users');
    }

    /**
     * The @mention dropdown's people (mentionableUsers()), for the page to
     * hand admin.js: each one's name, roles, and photo (User::avatarUrl()) or
     * initial.
     *
     * @return array<int, array{id: int, name: string, role: string, avatar: ?string, initial: string}>
     */
    public static function mentionDirectory(): array
    {
        return self::mentionableUsers()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->roles->pluck('name')->sort()->implode(', '),
            'avatar' => $user->avatarUrl(),
            'initial' => $user->initial(),
        ])->values()->all();
    }

    /**
     * Those of $mentionableUsers this comment @mentions by their full name,
     * each once — everyone of that name, when two share one. A name inside
     * a longer one ("@Riley Chenoweth") isn't Riley Chen: the longest names
     * are matched, and taken out, first.
     *
     * @param  Collection<int, User>  $mentionableUsers
     * @return Collection<int, User>
     */
    public function mentionedUsers(Collection $mentionableUsers): Collection
    {
        $body = (string) $this->body;

        return $mentionableUsers
            ->filter(fn (User $user) => filled($user->name))
            ->groupBy('name')
            ->sortByDesc(fn (Collection $named, string $name) => mb_strlen($name))
            ->filter(function (Collection $named, string $name) use (&$body): bool {
                $pattern = '/@'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u';

                if (! preg_match($pattern, $body)) {
                    return false;
                }

                $body = preg_replace($pattern, '', $body);

                return true;
            })
            ->flatten(1)
            ->unique('id')
            ->values();
    }

    /**
     * Sends everyone this comment @mentions a private message from its
     * author, so it's in their Messages (and bell): what was said, and which
     * RFQ it's on — whatever stage the RFQ is at, open, on hold, cancelled or
     * closed (since 2026-10-21; before, only closed ones). Not to the author
     * themselves. Returns who was sent one.
     *
     * @return Collection<int, User>
     */
    public function messageMentionedUsers(): Collection
    {
        $mentioned = $this->mentionedUsers(self::mentionableUsers())
            ->reject(fn (User $user) => $user->id === $this->user_id)
            ->values();

        $where = ($this->parent_id !== null ? 'in a reply ' : '').'on '.($this->rfq->isClosed() ? 'closed ' : '')."{$this->rfq->rfq_number} — {$this->rfq->subject}";

        foreach ($mentioned as $user) {
            PrivateMessage::query()->create([
                'sender_id' => $this->user_id,
                'recipient_id' => $user->id,
                'rfq_comment_id' => $this->id,
                'body' => "Mentioned you {$where}:\n{$this->body}",
            ]);
        }

        return $mentioned;
    }
}
