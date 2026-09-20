<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
        'sourcing_completed' => ['label' => 'Marked complete', 'icon' => 'bi-check-circle-fill', 'tone' => 'success'],
        'data_entry_completed' => ['label' => 'Completed in Data Entry', 'icon' => 'bi-check2-circle', 'tone' => 'success'],
        'returned_to_sourcing' => ['label' => 'Returned to Sourcing', 'icon' => 'bi-arrow-counterclockwise', 'tone' => 'danger'],
        'rejected' => ['label' => 'Rejected', 'icon' => 'bi-x-octagon-fill', 'tone' => 'danger'],
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
     * which one, or where it was sent back to ("Sam Rivera's part ·
     * RFQ1001-P2 of P3", "Returned to Sourcing") — or null when there's
     * nothing to add.
     */
    public function actionContext(): ?string
    {
        $meta = $this->meta ?? [];

        $context = collect([
            isset($meta['who']) ? "{$meta['who']}'s part" : null,
            $meta['label'] ?? null,
            isset($meta['stage']) ? "Returned to {$meta['stage']}" : null,
        ])->filter()->implode(' · ');

        return $context !== '' ? $context : null;
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
     * known mentionable user (the RFQ's Sourcing team — see the @mention
     * dropdown in admin.js) wrapped in a highlight span. Renders raw HTML,
     * so callers must use {!! !!}, never {{ }}.
     */
    public function bodyWithMentions(Collection $mentionableUsers): string
    {
        $escaped = e($this->body);

        $names = $mentionableUsers->pluck('name')->filter()->unique()
            // Longest names first, so "Riley Chen" isn't matched inside a
            // longer "Riley Chen Jr" before the full name gets its turn.
            ->sortByDesc(fn (string $name) => mb_strlen($name));

        foreach ($names as $name) {
            $needle = '@'.e($name);
            $escaped = str_replace($needle, '<span class="mention-highlight">'.$needle.'</span>', $escaped);
        }

        return $escaped;
    }
}
