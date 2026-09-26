<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * What a preference is until the person changes it on their Settings page.
     *
     * @var array<string, bool>
     */
    public const PREFERENCE_DEFAULTS = [
        'live_updates' => true,
        'celebrations' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /**
     * One of the person's Settings-page choices, or its default if they've
     * never changed it. See PREFERENCE_DEFAULTS.
     */
    public function preference(string $key): mixed
    {
        return $this->preferences[$key] ?? self::PREFERENCE_DEFAULTS[$key] ?? null;
    }

    /**
     * RFQs this user (typically a Sourcing team member) is assigned to.
     */
    public function assignedRfqs(): BelongsToMany
    {
        return $this->belongsToMany(Rfq::class)->withTimestamps();
    }

    /**
     * Private messages sent to this user — the unread ones are the count on
     * their Messages link.
     */
    public function receivedMessages(): HasMany
    {
        return $this->hasMany(PrivateMessage::class, 'recipient_id');
    }

    /**
     * Private messages this user has sent.
     */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(PrivateMessage::class, 'sender_id');
    }

    /**
     * Narrows to the people who hold a role. Unlike Spatie's role() it never
     * throws for a role that doesn't exist — there's just nobody in it.
     *
     * @param  Builder<User>  $query
     */
    public function scopeHoldingRole(Builder $query, string $role): void
    {
        $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role));
    }

    /**
     * The people who hold a role, by name — for the "Done by" pickers on
     * Admin's forms. Looked up once per request per role, since a page can
     * carry one on every row. See RfqController::doneBy().
     *
     * @return Collection<int, User>
     */
    public static function roleMembers(string $role): Collection
    {
        $request = request();
        $key = "role_members.{$role}";

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, static::holdingRole($role)->orderBy('name')->get(['id', 'name']));
        }

        return $request->attributes->get($key);
    }

    /**
     * Adds pending_rfqs_count/completed_rfqs_count to each user — their own
     * Sourcing workload, split by whether they've completed their part yet.
     * Used by the Assign Sourcing modal so whoever's assigning can see who's
     * already stretched thin. See Rfq::completeSourcingPartFor().
     */
    public function scopeWithSourcingWorkloadCounts(Builder $query): void
    {
        $query->withCount([
            'assignedRfqs as pending_rfqs_count' => fn (Builder $q) => $q->whereNull('rfq_user.completed_at'),
            'assignedRfqs as completed_rfqs_count' => fn (Builder $q) => $q->whereNotNull('rfq_user.completed_at'),
        ]);
    }
}
