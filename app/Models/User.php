<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\DataEntryIdle;
use App\Notifications\GmAssistantIdle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * Where profile photos are kept — the private disk, served only through
     * UserPhotoController::show() — and what one can be: a photo (no SVG, it
     * can carry script) of up to AVATAR_MAX_KILOBYTES. The browser squares
     * and shrinks it before it's sent (admin.js), so most are small.
     */
    public const AVATAR_DISK = 'local';

    /**
     * @var array<int, string>
     */
    public const AVATAR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const AVATAR_MAX_KILOBYTES = 5120;

    protected static function booted(): void
    {
        // Their photo goes with them.
        static::deleted(function (self $user) {
            if ($user->avatar_path !== null) {
                Storage::disk(self::AVATAR_DISK)->delete($user->avatar_path);
            }
        });
    }

    /**
     * Where their profile photo is shown from — a new address each time it
     * changes, so a browser never keeps showing the old one. Null without one.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null
            ? null
            : route('admin.users.photo', ['user' => $this->id, 'v' => substr(sha1($this->avatar_path), 0, 10)]);
    }

    /**
     * The letter that stands in for them without a photo.
     */
    public function initial(): string
    {
        return strtoupper(substr($this->name ?? '?', 0, 1));
    }

    /**
     * Whether this person can change $person's photo: their own, always;
     * someone else's only as one who changes people's details
     * (USER_DETAILS_ROLES, with users.edit) — and an Admin's only as an Admin.
     */
    public function canChangePhotoOf(self $person): bool
    {
        return $this->is($person)
            || ($this->canChangeUserDetails() && $this->can('users.edit') && ($this->hasRole('Admin') || ! $person->hasRole('Admin')));
    }

    /**
     * The panel's theme for this person — one of THEMES.
     */
    public function theme(): string
    {
        $theme = $this->preference('theme');

        return array_key_exists($theme, self::THEMES) ? $theme : self::PREFERENCE_DEFAULTS['theme'];
    }

    /**
     * The roles that see the Time Spent report. HR Executive has no part in the
     * RFQ workflow itself.
     *
     * @var array<int, string>
     */
    public const TIME_SPENT_REPORT_ROLES = ['Admin', 'Senior Operations', 'HR Executive'];

    /**
     * The roles that see the performance reports (Admin\PerformanceReportController):
     * Sourcing's daily and monthly reports, Data Entry's daily report and the
     * RFQ tracking sheet.
     *
     * @var array<int, string>
     */
    public const PERFORMANCE_REPORT_ROLES = ['Admin', 'Senior Operations', 'HR Executive'];

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
    public const ATTENDANCE_APPROVER_ROLES = ['Admin', 'HR Executive'];

    /**
     * The roles that change people's details — a name, an email — their
     * own on Settings → Profile, and others' on the Users page (with
     * users.edit). Everyone else's are kept for them.
     *
     * @var array<int, string>
     */
    public const USER_DETAILS_ROLES = ['Admin', 'HR Executive'];

    /**
     * The roles that give a role, or one person, working hours of their own
     * (WorkingSchedule) — on Settings → Role Hours and on a person's page.
     * The company's working hours stay the Admin's.
     *
     * @var array<int, string>
     */
    public const WORKING_HOURS_ROLES = ['Admin', 'HR Executive'];

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
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Whether an Admin has deactivated the account: it can't sign in (and is
     * signed out of any session it had — EnsureAccountIsActive), and it's
     * left out of every list people are picked from (scopeActive(),
     * scopeHoldingRole()) — but its name stays on everything it did.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Who deactivated the account.
     */
    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deactivated_by');
    }

    /**
     * Narrows to the accounts that haven't been deactivated.
     *
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('users.deactivated_at');
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
     * Whether this person changes people's details, their own included —
     * see USER_DETAILS_ROLES.
     */
    public function canChangeUserDetails(): bool
    {
        return $this->hasAnyRole(self::USER_DETAILS_ROLES);
    }

    /**
     * Whether this person sees only the RFQs they created (since 2026-10-15):
     * someone whose one part in the workflow is Business Development — their
     * lists, Closed included, their dashboard, the WC search, and each RFQ's
     * own page and actions (Rfq::isVisibleTo(), EnsureRfqIsVisible). Holding
     * any other workflow role, or Admin, they see what that role does.
     */
    public function seesOnlyOwnRfqs(): bool
    {
        return $this->hasRole('Business Development')
            && ! $this->hasAnyRole(['Admin', ...array_diff(Rfq::WORKFLOW_ROLES, ['Business Development'])]);
    }

    /**
     * Whether this person sees only the RFQs Sourcing gave them (since
     * 2026-10-15): someone whose one part in the workflow is Data Entry —
     * every list, Closed included, the WC search, and each RFQ's own page and
     * actions (Rfq::scopeVisibleTo(), Rfq::isVisibleTo(), EnsureRfqIsVisible).
     * Holding any other workflow role, or Admin, they see what that role does.
     */
    public function seesOnlyAssignedRfqs(): bool
    {
        return $this->hasRole('Data Entry')
            && ! $this->hasAnyRole(['Admin', ...array_diff(Rfq::WORKFLOW_ROLES, ['Data Entry'])]);
    }

    /**
     * Whether this person's RFQ timeline is Sourcing's and Data Entry's alone
     * (since 2026-10-20, Rfq::activityTimelineFor()): someone in Sourcing or
     * Data Entry — or both — holding no other workflow role, nor Admin or HR
     * Executive. Everyone else sees the whole timeline.
     */
    public function seesOnlySourcingAndDataEntryTimeline(): bool
    {
        $roles = $this->roles->pluck('name');

        return $roles->intersect(['Sourcing', 'Data Entry'])->isNotEmpty()
            && $roles->diff(['Sourcing', 'Data Entry'])->isEmpty();
    }

    /**
     * Whether this person holds Sourcing or Data Entry — whose actions a
     * Sourcing or Data Entry viewer's timeline shows (Rfq::activityTimelineFor()).
     */
    public function isSourcingOrDataEntry(): bool
    {
        return $this->roles->pluck('name')->intersect(['Sourcing', 'Data Entry'])->isNotEmpty();
    }

    /**
     * Whether this person sees every RFQ — not just their own
     * (seesOnlyOwnRfqs()) or what was given them (seesOnlyAssignedRfqs()).
     */
    public function seesEveryRfq(): bool
    {
        return ! $this->seesOnlyOwnRfqs() && ! $this->seesOnlyAssignedRfqs();
    }

    /**
     * Whose RFQs this person sees, as the creator to narrow a query to
     * (Rfq::scopeCreatedBy()) — theirs, if they see only their own
     * (seesOnlyOwnRfqs()); null, everyone's.
     */
    public function ownRfqsOnlyId(): ?int
    {
        return $this->seesOnlyOwnRfqs() ? $this->id : null;
    }

    /**
     * Whether this person sets a role's or someone's own working hours —
     * see WORKING_HOURS_ROLES.
     */
    public function canManageWorkingHours(): bool
    {
        return $this->hasAnyRole(self::WORKING_HOURS_ROLES);
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
     * Whether this person sees the performance reports — see
     * PERFORMANCE_REPORT_ROLES.
     */
    public function canViewPerformanceReports(): bool
    {
        return $this->hasAnyRole(self::PERFORMANCE_REPORT_ROLES);
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
     * Whether this person has the Critical monitor in their top bar
     * (DashboardController::critical()) — what's overdue, idle or urgent
     * across the operation: Admin, and Senior Operations, who run it.
     */
    public function canSeeCriticalMonitor(): bool
    {
        return $this->hasAnyRole(['Admin', 'Senior Operations']);
    }

    /**
     * Whether this person can send a Sourcing part, still with Sourcing, back
     * to Senior Operations to assign again (RfqController::returnSeniorOps())
     * — Admin alone, on the member's behalf. Sourcing's own Return to Senior
     * Operations button was taken away on 2026-10-12.
     */
    public function canSendSourcingPartsBackToSeniorOps(): bool
    {
        return $this->hasRole('Admin');
    }

    /**
     * RFQs this user (typically a Sourcing team member) is assigned to.
     */
    public function assignedRfqs(): BelongsToMany
    {
        return $this->belongsToMany(Rfq::class)->withTimestamps();
    }

    /**
     * Their own working time on top of everyone's working hours — see
     * ExtraWorkingHour.
     */
    public function extraWorkingHours(): HasMany
    {
        return $this->hasMany(ExtraWorkingHour::class)->orderBy('start');
    }

    /**
     * A Sourcing member's usual person in Data Entry, set by an Admin on their
     * page — whom their "Assign to Data Entry" picks to begin with. They can
     * still pick someone else, or anyone there.
     */
    public function defaultDataEntryUser(): BelongsTo
    {
        return $this->belongsTo(self::class, 'default_data_entry_user_id');
    }

    /**
     * The Sourcing members whose usual person in Data Entry this is.
     */
    public function defaultForSourcing(): HasMany
    {
        return $this->hasMany(self::class, 'default_data_entry_user_id');
    }

    /**
     * Their own working weeks, every version (WorkingSchedule) — read the one
     * counting through WorkingSchedule::currentFor().
     */
    public function workingSchedules(): HasMany
    {
        return $this->hasMany(WorkingSchedule::class);
    }

    /**
     * What the top bar's bell lists for them (layouts/_notification_bell),
     * newest first, up to $limit: their alerts (database notifications, such
     * as DataEntryIdle) and the private messages sent to them — one line per
     * person, their latest message, saying how many are unread. And how many
     * of both are unread, for its count.
     *
     * @return array{items: SupportCollection<int, array{kind: string, at: CarbonInterface, unread: bool, url: string, icon: string, text: string, note: string}>, unread: int}
     */
    public function bellItems(int $limit = 8): array
    {
        $alerts = $this->notifications()->latest()->limit($limit)->get()
            ->map(fn ($alert) => [
                'kind' => 'alert',
                'at' => $alert->created_at,
                'unread' => $alert->read_at === null,
                'url' => route('admin.notifications.open', $alert->id),
                // An idle alert (DataEntryIdle) says nothing of these; others say their own.
                'icon' => $alert->data['icon'] ?? 'bi-hourglass-bottom',
                'text' => $alert->data['message'] ?? 'Alert',
                'note' => $alert->data['note'] ?? 'message them',
            ]);

        $unreadBySender = $this->receivedMessages()->unread()->groupBy('sender_id')->selectRaw('sender_id, count(*) as total')->pluck('total', 'sender_id');

        $latestFromEach = PrivateMessage::query()
            ->whereIn('id', $this->receivedMessages()->groupBy('sender_id')->selectRaw('max(id)'))
            ->with('sender')
            ->latest('id')
            ->limit($limit)
            ->get();

        $messages = $latestFromEach
            ->filter(fn (PrivateMessage $message) => $message->sender !== null)
            ->map(function (PrivateMessage $message) use ($unreadBySender) {
                $unread = (int) ($unreadBySender[$message->sender_id] ?? 0);

                return [
                    'kind' => 'message',
                    'at' => $message->created_at,
                    'unread' => $unread > 0,
                    'url' => route('admin.messages.show', $message->sender),
                    'icon' => 'bi-chat-dots',
                    'text' => $message->sender->name.': '.Str::limit($message->body, 80),
                    'note' => match (true) {
                        $unread > 1 => "{$unread} new messages",
                        $unread === 1 => 'new message',
                        default => 'message',
                    },
                ];
            });

        return [
            'items' => $alerts->concat($messages)->sortByDesc('at')->take($limit)->values(),
            'unread' => $this->unreadNotifications()->count() + (int) $unreadBySender->sum(),
        ];
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
     * part waiting for them came in — given to them, or to anyone in Data
     * Entry (Rfq::dataEntryWaitingSince()) —
     * that many of their working seconds (own extra hours included), less
     * the time they're off on a half day (Attendance::timeOff()) —
     * and when Senior Operations was told about it, if they have been
     * (DataEntryIdle, sent by App\Console\Commands\AlertIdleDataEntry).
     * Null when it doesn't apply: the alert's off, nothing's waiting, they've
     * a part running (RfqStep::runningDataEntryOf()), or today's attendance
     * sheet has them on leave.
     *
     * @return array{idle_from: CarbonImmutable, idle_seconds: int, alert_after: int, waiting: int, time_off: array{start: string, end: string}|null, alerted_at: ?CarbonImmutable}|null
     */
    public function dataEntryIdleness(?CarbonInterface $now = null): ?array
    {
        // Only what's theirs to start: given to them, or to anyone in Data Entry.
        return $this->idlenessAt('data_entry', Setting::dataEntryIdleAlert(), Rfq::dataEntryWaitingSince($this->id),
            RfqStep::runningDataEntryOf($this) !== null, DataEntryIdle::class, 'data_entry_user_id', $now);
    }

    /**
     * Where this GM Assistant person stands against their idle alert
     * (Setting::gmAssistantIdleAlert()) at $now — the same as
     * dataEntryIdleness(), with the parts waiting for GM Assistant to start
     * (Rfq::gmAssistantWaitingSince()), their running part
     * (RfqStep::runningGmAssistantOf()) and the alerts about them
     * (GmAssistantIdle, sent by App\Console\Commands\AlertIdleGmAssistant).
     *
     * @return array{idle_from: CarbonImmutable, idle_seconds: int, alert_after: int, waiting: int, time_off: array{start: string, end: string}|null, alerted_at: ?CarbonImmutable}|null
     */
    public function gmAssistantIdleness(?CarbonInterface $now = null): ?array
    {
        return $this->idlenessAt('gm_assistant', Setting::gmAssistantIdleAlert(), Rfq::gmAssistantWaitingSince(),
            RfqStep::runningGmAssistantOf($this) !== null, GmAssistantIdle::class, 'gm_assistant_user_id', $now);
    }

    /**
     * Idleness at $step against $alert (dataEntryIdleness(), gmAssistantIdleness()):
     * null when the alert's off, nothing's $waiting, they've a part $running,
     * or today's sheet has them on leave; otherwise idle since the latest of
     * the start of today, when they last had a part running at $step, and when
     * the oldest waiting part came in — in their working seconds less a half
     * day's time off — and when they were last alerted about ($alertType,
     * naming them under $alertKey) since they were free.
     *
     * @param  array{enabled: bool, minutes: int}  $alert
     * @param  SupportCollection<int, CarbonImmutable>  $waiting
     * @return array{idle_from: CarbonImmutable, idle_seconds: int, alert_after: int, waiting: int, time_off: array{start: string, end: string}|null, alerted_at: ?CarbonImmutable}|null
     */
    private function idlenessAt(string $step, array $alert, SupportCollection $waiting, bool $running, string $alertType, string $alertKey, ?CarbonInterface $now): ?array
    {
        if (! $alert['enabled'] || $waiting->isEmpty() || $running) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? now());
        $local = $now->setTimezone(Setting::timezone());
        $mark = Attendance::markOn($this->id, $local->toDateString());

        if ($mark?->status === Attendance::ABSENT) {
            return null;
        }

        $timeOff = $mark?->timeOff();
        $lastRunning = RfqStep::query()->where('step', $step)->where('worked_by', $this->id)->max('ended_at');
        $startOfToday = $local->startOfDay()->setTimezone($now->getTimezone());
        $freeSince = $lastRunning !== null ? max($startOfToday, CarbonImmutable::parse($lastRunning)) : $startOfToday;
        $idleFrom = max($freeSince, $waiting->min());
        $alertedAt = DatabaseNotification::query()
            ->where('type', $alertType)
            ->where("data->{$alertKey}", $this->id)
            ->where('created_at', '>=', $freeSince)
            ->min('created_at');

        return [
            'idle_from' => $idleFrom,
            'idle_seconds' => (int) collect(Setting::workingSecondsWithHalfDaysOff($idleFrom, $now, $this->id))
                ->map(fn (array $day, string $date) => $day['all'] - ($timeOff !== null ? Setting::workingSecondsInTimeOff($idleFrom, $now, $date, $timeOff, $this->id) : 0))
                ->sum(),
            'alert_after' => $alert['minutes'] * 60,
            'waiting' => $waiting->count(),
            'time_off' => $timeOff,
            'alerted_at' => $alertedAt !== null ? CarbonImmutable::parse($alertedAt) : null,
        ];
    }

    /**
     * Narrows to the people who hold a role — and can work: not deactivated
     * (isDeactivated()). Unlike Spatie's role() it never throws for a role
     * that doesn't exist — there's just nobody in it.
     *
     * @param  Builder<User>  $query
     */
    public function scopeHoldingRole(Builder $query, string $role): void
    {
        $query->active()->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role));
    }

    /**
     * Narrows to the people who hold any of $roles and can work — as
     * holdingRole(), for several roles at once.
     *
     * @param  Builder<User>  $query
     * @param  array<int, string>  $roles
     */
    public function scopeHoldingAnyRole(Builder $query, array $roles): void
    {
        $query->active()->whereHas('roles', fn (Builder $named) => $named->whereIn('name', $roles));
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
