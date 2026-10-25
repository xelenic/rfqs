<?php

namespace App\Models;

use Database\Factories\MessageGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A group chat in Messages → Groups (Admin\MessageGroupController): its name,
 * what it's for, its members (message_group_user, each with the last of its
 * messages they've read — what's after it is unread for them) and what's
 * said in it (GroupMessage). An Admin sets it up — names it, adds and takes
 * people off, deletes it — and only its members read it and write in it.
 * Settings → Messaging's limits on who can message whom don't apply: an
 * Admin chose who's in it.
 */
class MessageGroup extends Model
{
    /** @use HasFactory<MessageGroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who's in it, each with the last message they've read.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('last_read_message_id')->withTimestamps();
    }

    /**
     * What's been said in it, oldest first.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(GroupMessage::class)->oldest('id');
    }

    /**
     * Whether $user is in it.
     */
    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Sets who's in it to $userIds: those new to it start with everything
     * said so far read — the history's there for them, not a pile of unread
     * — and those taken off it leave.
     *
     * @param  array<int, int>  $userIds
     */
    public function setMembers(array $userIds): void
    {
        $latest = $this->messages()->max('id');
        $current = $this->members()->pluck('users.id')->all();

        $this->members()->detach(array_diff($current, $userIds));
        $this->members()->attach(array_fill_keys(array_values(array_diff($userIds, $current)), ['last_read_message_id' => $latest]));
    }

    /**
     * Marks everything said in it read for $user — and only when there's
     * something they hadn't read: a write on every visit would keep a live
     * page refreshing itself.
     */
    public function markReadFor(User $user): void
    {
        $latest = $this->messages()->max('id');

        if ($latest === null) {
            return;
        }

        $behind = DB::table('message_group_user')
            ->where('message_group_id', $this->id)
            ->where('user_id', $user->id)
            ->where(fn ($membership) => $membership->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $latest));

        if ($behind->exists()) {
            $behind->update(['last_read_message_id' => $latest, 'updated_at' => now()]);
        }
    }

    /**
     * How many messages are unread for $user in each of their groups — what
     * others have said since the last they read: group id => count.
     *
     * @return Collection<int, int>
     */
    public static function unreadCountsFor(User $user): Collection
    {
        return DB::table('message_group_user as membership')
            ->join('group_messages', 'group_messages.message_group_id', '=', 'membership.message_group_id')
            ->where('membership.user_id', $user->id)
            ->where(fn ($others) => $others->whereNull('group_messages.user_id')->orWhere('group_messages.user_id', '!=', $user->id))
            ->whereRaw('group_messages.id > coalesce(membership.last_read_message_id, 0)')
            ->groupBy('membership.message_group_id')
            ->selectRaw('membership.message_group_id as group_id, count(*) as total')
            ->pluck('total', 'group_id')
            ->map(fn ($total) => (int) $total);
    }

    /**
     * Marks everything in all of $user's groups read — the bell's Mark all
     * as read.
     */
    public static function markAllReadFor(User $user): void
    {
        $user->messageGroups()->get()->each(fn (self $group) => $group->markReadFor($user));
    }
}
