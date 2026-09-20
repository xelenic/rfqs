<?php

namespace App\Models;

use Database\Factories\PrivateMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private message from one user to another — sent from the "Message"
 * button on a person's hover card (see layouts/_user_card.blade.php) and
 * read in Messages. Only its sender and recipient can see it.
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
}
