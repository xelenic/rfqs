<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class RfqComment extends Model
{
    protected $fillable = [
        'user_id',
        'parent_id',
        'body',
    ];

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
        return $this->hasMany(self::class, 'parent_id')->oldest();
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
