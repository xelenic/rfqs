<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\DataEntryIdle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
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
        'theme' => 'light',
    ];

    /**
     * The themes the panel comes in — see the theme preference, and admin.css.
     *
     * @var array<string, string>
     */
    public const THEMES = ['light' => 'Light', 'dark' => 'Dark'];

    /**
     * The panel's theme for this person — one of THEMES.
     */
    public function theme(): string
    {
        $theme = $this->preference('theme');

        return array_key_exists($theme, self::THEMES) ? $theme : self::PREFERENCE_DEFAULTS['theme'];
    }

    /**
     * The roles that see the Time Spent report. HR Manager has no part in the
     * RFQ workflow itself.
     *
     * @var array<int, string>
     */
    public const TIME_SPENT_REPORT_ROLES = ['Admin', 'Senior Operations', 'HR Manager'];

    /**
     * The roles that fill in and submit the daily attendance sheet.
     *
     * @var array<int, string>
     */
    public const ATTENDANCE_KEEPER_ROLES = ['Admin', 'Senior Operations'];

    /**
     * The roles that review a submitted attendance sheet — approve it, or
     * return it to be corrected.
     *
     * @var array<int, string>
     */
    public const ATTENDANCE_APPROVER_ROLES = ['Admin', 'HR Manager'];

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
     * Whether this person sees the Time Spent report — see
     * TIME_SPENT_REPORT_ROLES.
     */
    public function canViewTimeSpentReport(): bool
    {
        return $this->hasAnyRole(self::TIME_SPENT_REPORT_ROLES);
    }

    /**
     * Whether this person fills in and submits the daily attendance sheet
     * (AttendanceSheet) — see ATTENDANCE_KEEPER_ROLES.
     */
    public function canManageAttendance(): bool
    {
        return $this->hasAnyRole(self::ATTENDANCE_KEEPER_ROLES);
    }

    /**
     * Whether this person reviews submitted attendance sheets — approves
     * them, or returns them to be corrected. See ATTENDANCE_APPROVER_ROLES.
     */
    public function canApproveAttendance(): bool
    {
        return $this->hasAnyRole(self::ATTENDANCE_APPROVER_ROLES);
    }

    /**
     * Whether this person sees the attendance page — to keep the sheets, or
     * to review them.
     */
    public function canViewAttendance(): bool
    {
        return $this->canManageAttendance() || $this->canApproveAttendance();
    }

    /**
     * Whether this person can put an RFQ on hold, cancel it, or set it going
     * again — Senior Operations, and Admin (Rfq::changeStatus()).
     */
    public function canChangeRfqStatus(): bool
    {
        return $this->hasAnyRole(['Senior Operations', 'Admin']);
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
     * Where this Data Entry person stands against the idle alert
     * (Setting::dataEntryIdleAlert()) at $now: idle since the latest of the
     * start of today, when they last had a part running, and when the oldest
     * part waiting for Data Entry came in (Rfq::dataEntryWaitingSince()) —
     * that many working seconds, less the half of a half day they're off —
     * and when Senior Operations was told about it, if they have been
     * (DataEntryIdle, sent by App\Console\Commands\AlertIdleDataEntry).
     * Null when it doesn't apply: the alert's off, nothing's waiting, they've
     * a part running (RfqStep::runningDataEntryOf()), or today's attendance
     * sheet has them on leave.
     *
     * @return array{idle_from: CarbonImmutable, idle_seconds: int, alert_after: int, waiting: int, half_off: ?string, alerted_at: ?CarbonImmutable}|null
     */
    public function dataEntryIdleness(?CarbonInterface $now = null): ?array
    {
        $alert = Setting::dataEntryIdleAlert();
        $waiting = Rfq::dataEntryWaitingSince();

        if (! $alert['enabled'] || $waiting->isEmpty() || RfqStep::runningDataEntryOf($this) !== null) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? now());
        $local = $now->setTimezone(Setting::timezone());
        $mark = Attendance::markOn($this->id, $local->toDateString());

        if ($mark?->status === Attendance::ABSENT) {
            return null;
        }

        $halfOff = $mark?->status === Attendance::HALF_DAY ? $mark->half_off : null;
        $lastRunning = RfqStep::query()->where('step', 'data_entry')->where('worked_by', $this->id)->max('ended_at');
        $startOfToday = $local->startOfDay()->setTimezone($now->getTimezone());
        $freeSince = $lastRunning !== null ? max($startOfToday, CarbonImmutable::parse($lastRunning)) : $startOfToday;
        $idleFrom = max($freeSince, $waiting->min());
        $alertedAt = DatabaseNotification::query()
            ->where('type', DataEntryIdle::class)
            ->where('data->data_entry_user_id', $this->id)
            ->where('created_at', '>=', $freeSince)
            ->min('created_at');

        return [
            'idle_from' => $idleFrom,
            'idle_seconds' => (int) collect(Setting::workingSecondsWithHalfDaysOff($idleFrom, $now))
                ->sum(fn (array $day) => $day['all'] - ($halfOff !== null ? $day[$halfOff] : 0)),
            'alert_after' => $alert['minutes'] * 60,
            'waiting' => $waiting->count(),
            'half_off' => $halfOff,
            'alerted_at' => $alertedAt !== null ? CarbonImmutable::parse($alertedAt) : null,
        ];
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
