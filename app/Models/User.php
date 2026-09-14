<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * RFQs this user (typically a Sourcing team member) is assigned to.
     */
    public function assignedRfqs(): BelongsToMany
    {
        return $this->belongsToMany(Rfq::class)->withTimestamps();
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
