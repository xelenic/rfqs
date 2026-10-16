<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Notifications\AccountCreated;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Throwable;

class UserController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:users.view', only: ['index', 'show']),
            new Middleware('permission:users.create', only: ['store', 'sendPasswordLink']),
            new Middleware('permission:users.edit', only: ['update']),
            new Middleware('permission:users.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $users = User::query()
            ->with(['roles', 'extraWorkingHours' => fn ($ranges) => $ranges->current()])
            ->when($request->string('search')->trim()->toString(), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            // Everyone, or just the active or the deactivated (?state=).
            ->when($request->query('state') === 'active', fn ($query) => $query->active())
            ->when($request->query('state') === 'deactivated', fn ($query) => $query->whereNotNull('deactivated_at'))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $request->string('search')->toString(),
            'state' => in_array($request->query('state'), ['active', 'deactivated'], true) ? $request->query('state') : null,
            'deactivatedCount' => User::query()->whereNotNull('deactivated_at')->count(),
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    /**
     * One person's page, opened from the Users list: who they are and what
     * they do here — their RFQ work in numbers (workOf()), the parts in
     * their hands now, what they've done lately and, for the people whose
     * time is tracked, the last two weeks' attendance and their extra hours.
     * The list's own actions are here too, and come back to this page.
     */
    public function show(Request $request, User $user): View
    {
        $user->load(['roles', 'extraWorkingHours' => fn ($ranges) => $ranges->current(), 'defaultDataEntryUser', 'defaultForSourcing']);
        $isTracked = $user->hasAnyRole(array_keys(RfqStep::ROLE_STEPS));

        return view('admin.users.show', [
            'user' => $user,
            'isTracked' => $isTracked,
            'canManage' => $request->user()->hasRole('Admin') || ! $user->hasRole('Admin'),
            'roles' => $this->assignableRoles($request->user()),
            'work' => $this->workOf($user, $isTracked),
            'partsInHand' => $this->partsInHand($user),
            'activity' => $this->activityOf($user),
            'attendance' => $isTracked ? $this->recentAttendanceOf($user) : null,
            ...$this->hoursOf($user),
            // Whom in Data Entry a Sourcing member's parts go to, to begin
            // with — for an Admin to set.
            'dataEntryPeople' => $user->hasRole('Sourcing') && $request->user()->hasRole('Admin')
                ? User::query()->holdingRole('Data Entry')->orderBy('name')->get()
                : collect(),
        ]);
    }

    /**
     * The working week their time counts in today (Setting::weekOn()) and
     * where it's from — their own, their role's or the company's; their own,
     * if they have one (WorkingSchedule); and what they'd work without it:
     * the first of their roles with its own hours, else the company's.
     *
     * @return array{workingWeek: array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>, weekSource: array{source: string, role: ?string}, ownHours: ?WorkingSchedule, fallbackHours: string}
     */
    private function hoursOf(User $user): array
    {
        $today = Setting::today();
        $roleWithHours = WorkingSchedule::roleOrder()
            ->whereIn('id', $user->roles->pluck('id'))
            ->first(fn (Role $role) => WorkingSchedule::currentFor($role) !== null);

        return [
            'workingWeek' => Setting::weekOn($today, $user->id),
            'weekSource' => Setting::weekSourceOn($today, $user->id),
            'ownHours' => WorkingSchedule::currentFor($user),
            'fallbackHours' => $roleWithHours !== null ? "{$roleWithHours->name}'s hours" : 'the company\'s working hours',
        ];
    }

    /**
     * Their RFQ work in numbers. Each figure is for whoever holds the role
     * it's about — or has done it anyway, like someone who's since changed
     * role: the RFQs they've created and closed, the Sourcing parts they've
     * done, the parts they've sent to finalize. And for the people whose
     * time is tracked, their working time on RFQs begun in the last 30 days,
     * counted as the Time Spent report does — only the days they were
     * present.
     *
     * @return array<int, array{label: string, value: string, caption: string, icon: string, tone: string}>
     */
    private function workOf(User $user, bool $isTracked): array
    {
        $figures = [
            ['role' => 'Business Development', 'label' => 'RFQs created', 'count' => Rfq::query()->where('created_by', $user->id)->count(), 'caption' => 'All time', 'icon' => 'bi-plus-circle', 'tone' => 'primary'],
            ['role' => 'Sourcing', 'label' => 'Parts sourced', 'count' => DB::table('rfq_user')->where('user_id', $user->id)->whereNotNull('completed_at')->count(), 'caption' => 'Assigned to Data Entry', 'icon' => 'bi-check2-circle', 'tone' => 'success'],
            ['role' => 'Data Entry', 'label' => 'Sent to finalize', 'count' => DB::table('rfq_user')->where('data_entry_completed_by', $user->id)->count(), 'caption' => 'Parts entered', 'icon' => 'bi-send-check', 'tone' => 'violet'],
            ['role' => 'Business Development', 'label' => 'RFQs closed', 'count' => Rfq::query()->where('bd_closed_by', $user->id)->count(), 'caption' => 'All time', 'icon' => 'bi-flag-fill', 'tone' => 'teal'],
        ];

        $work = collect($figures)
            ->filter(fn (array $figure) => $figure['count'] > 0 || $user->hasRole($figure['role']))
            ->map(fn (array $figure) => [
                'label' => $figure['label'],
                'value' => (string) $figure['count'],
                'caption' => $figure['caption'],
                'icon' => $figure['icon'],
                'tone' => $figure['tone'],
            ])
            ->values()
            ->all();

        if ($isTracked) {
            $now = now();
            $book = Attendance::book([$user->id]);
            $seconds = RfqStep::query()
                ->where('started_at', '>=', $now->copy()->subDays(30))
                ->where(fn ($steps) => $steps
                    ->where('worked_by', $user->id)
                    ->orWhere(fn ($unclaimed) => $unclaimed->whereNull('worked_by')->where('assignee_id', $user->id)))
                ->get()
                ->filter(fn (RfqStep $step) => $step->workerId() === $user->id)
                ->sum(fn (RfqStep $step) => $step->secondsByAttendance($book, $now)['present']);

            $work[] = ['label' => 'Working time', 'value' => Setting::durationLabel((int) $seconds), 'caption' => 'On RFQs, last 30 days', 'icon' => 'bi-stopwatch', 'tone' => 'warning'];
        }

        return $work;
    }

    /**
     * The parts in their hands now, longest-held first: Sourcing's to
     * complete — those sent back for rework among them — or to finalize, and
     * the one Data Entry has started and not yet sent on. Only RFQs still
     * pending, and no part stopped on its own.
     *
     * @return SupportCollection<int, array{rfq: Rfq, label: string, state: string, badge: string, since: ?CarbonInterface}>
     */
    private function partsInHand(User $user): SupportCollection
    {
        $isSourcingWork = fn ($parts) => $parts->where('rfq_user.user_id', $user->id)
            ->whereNull('rfq_user.finalized_at')
            ->where(fn ($open) => $open->whereNull('rfq_user.completed_at')->orWhereNotNull('rfq_user.data_entry_completed_at'));
        $isDataEntryWork = fn ($parts) => $parts->where('rfq_user.data_entry_started_by', $user->id)
            ->whereNull('rfq_user.data_entry_completed_at');

        return Rfq::query()
            ->where('rfqs.status', 'Pending')
            ->whereHas('assignees', fn ($parts) => $parts->whereNull('rfq_user.status')
                ->where(fn ($either) => $either->where($isSourcingWork)->orWhere($isDataEntryWork)))
            ->with('assignees')
            ->get()
            ->flatMap(fn (Rfq $rfq) => $rfq->assignees
                ->filter(fn (User $holder) => $holder->pivot->status === null)
                ->map(function (User $holder) use ($rfq, $user) {
                    $part = $holder->pivot;
                    $isTheirs = $holder->id === $user->id;

                    [$state, $badge, $since] = match (true) {
                        $isTheirs && $part->isAwaitingFinalize() => ['To finalize', 'badge-soft-info', $part->data_entry_completed_at],
                        $isTheirs && $part->completed_at === null => [$part->progressLabel(), $part->progressBadgeClass(), $part->returned_at ?? $part->created_at],
                        (int) $part->data_entry_started_by === $user->id && $part->data_entry_started_at !== null && $part->data_entry_completed_at === null => ['Data Entry started', 'badge-soft-primary', $part->data_entry_started_at],
                        default => [null, null, null],
                    };

                    return $state === null ? null : [
                        'rfq' => $rfq,
                        'label' => $rfq->partNumberLabel($part->part_number),
                        'state' => $state,
                        'badge' => $badge,
                        'since' => $since,
                    ];
                })
                ->filter())
            ->sortBy(fn (array $item) => $item['since']?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * What they've done lately, latest first: each workflow step they took —
     * the comment it left (RfqComment::ACTIONS) — and each RFQ they created.
     *
     * @return SupportCollection<int, array{at: CarbonInterface, rfq: Rfq, label: string, context: ?string, icon: string, tone: string}>
     */
    private function activityOf(User $user, int $limit = 10): SupportCollection
    {
        $actions = RfqComment::query()
            ->where('user_id', $user->id)
            ->whereNotNull('action')
            ->with('rfq')
            ->latest()
            ->limit($limit)
            ->get()
            ->filter(fn (RfqComment $comment) => $comment->rfq !== null)
            ->map(fn (RfqComment $comment) => [
                'at' => $comment->created_at,
                'rfq' => $comment->rfq,
                'label' => (string) $comment->actionLabel(),
                'context' => $comment->actionContext(),
                'icon' => $comment->actionIcon(),
                'tone' => $comment->actionTone(),
            ]);

        $created = Rfq::query()
            ->where('created_by', $user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Rfq $rfq) => [
                'at' => $rfq->created_at,
                'rfq' => $rfq,
                'label' => 'Created',
                'context' => null,
                'icon' => 'bi-plus-circle',
                'tone' => 'primary',
            ]);

        return $actions->concat($created)->sortByDesc('at')->take($limit)->values();
    }

    /**
     * Their attendance on each of their working days of the last two weeks, latest
     * first, as HR Manager approved it (Attendance::statusIn()) — from the
     * day attendance started; null while it isn't kept
     * (Setting::attendanceSince()).
     *
     * @return array<int, array{date: CarbonImmutable, status: string}>|null
     */
    private function recentAttendanceOf(User $user): ?array
    {
        $since = Setting::attendanceSince();

        if ($since === null) {
            return null;
        }

        $book = Attendance::book([$user->id]);
        $today = CarbonImmutable::now(Setting::timezone())->startOfDay();

        return collect(range(0, 13))
            ->map(fn (int $daysAgo) => $today->subDays($daysAgo))
            ->filter(fn (CarbonImmutable $day) => $day->toDateString() >= $since && Setting::weekOn($day->toDateString(), $user->id)[strtolower($day->format('l'))]['working'])
            ->map(fn (CarbonImmutable $day) => ['date' => $day, 'status' => Attendance::statusIn($book, $user->id, $day->toDateString())])
            ->values()
            ->all();
    }

    /**
     * A Sourcing member's usual person in Data Entry — whom their "Assign to
     * Data Entry" picks to begin with — or nobody (anyone there). Admin only,
     * from the member's page. See User::defaultDataEntryUser().
     */
    public function updateDefaultDataEntry(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can set someone\'s Data Entry person.');
        abort_unless($user->hasRole('Sourcing'), 422, 'Only someone in Sourcing hands parts to Data Entry.');

        $validated = $request->validateWithBag('default_data_entry', [
            'data_entry_user_id' => ['nullable', 'integer', Rule::in(User::query()->holdingRole('Data Entry')->pluck('id'))],
        ], [
            'data_entry_user_id.in' => 'Pick someone in Data Entry from the list.',
        ]);

        $user->forceFill(['default_data_entry_user_id' => $validated['data_entry_user_id'] ?? null])->save();
        $person = $user->defaultDataEntryUser()->first();

        return redirect()->route('admin.users.show', $user)->with('status', $person
            ? "{$user->name}'s parts go to {$person->name} in Data Entry to begin with."
            : "{$user->name}'s parts go to anyone in Data Entry to begin with.");
    }

    /**
     * Back to where something was done to $user from: their own page
     * (return_to "show"), or the Users list.
     */
    private function backFrom(Request $request, User $user): RedirectResponse
    {
        return $request->input('return_to') === 'show'
            ? redirect()->route('admin.users.show', $user)
            : redirect()->route('admin.users.index');
    }

    /**
     * Only an Admin touches an Admin's account: anyone else changing its
     * email, or sending it a password link, could take it over.
     */
    private function abortUnlessCanManage(User $by, User $user): void
    {
        abort_if($user->hasRole('Admin') && ! $by->hasRole('Admin'), 403, 'Only an Admin can change an Admin\'s account.');
    }

    /**
     * The roles $user can give someone: any, for an Admin — and every one
     * but Admin for anyone else who adds people (HR Manager).
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(User $user): Collection
    {
        return Role::query()
            ->when(! $user->hasRole('Admin'), fn ($roles) => $roles->where('name', '!=', 'Admin'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Adds someone — HR Manager or Admin. By default with no password of
     * their own yet: they're emailed a link to set it (AccountCreated, good
     * for a week and once); if the email can't be sent, the account still
     * stands, and says so — Send link tries again. An Admin can set a
     * password for them instead (sign_in "password"), and then no email
     * goes. Only an Admin can make an Admin.
     */
    public function store(Request $request): RedirectResponse
    {
        $isAdmin = $request->user()->hasRole('Admin');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'sign_in' => ['nullable', Rule::in($isAdmin ? ['email_link', 'password'] : ['email_link'])],
            'password' => ['exclude_unless:sign_in,password', 'required', 'string', 'min:8', 'confirmed'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::in($this->assignableRoles($request->user())->pluck('name'))],
        ], [
            'sign_in.in' => 'Only an Admin can set a password for someone — email them a link instead.',
            'roles.*.in' => 'Pick from the roles listed.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator, 'create')->withInput($request->except(['password', 'password_confirmation']));
        }

        $validated = $validator->validated();
        $setsPassword = ($validated['sign_in'] ?? 'email_link') === 'password';

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Without one set here nobody knows it — they set their own from the email.
            'password' => Hash::make($setsPassword ? $validated['password'] : Str::random(64)),
        ]);

        $user->syncRoles($validated['roles'] ?? []);

        if ($setsPassword) {
            return redirect()->route('admin.users.index')->with('status', 'User created — they can sign in with the password you set.');
        }

        return $this->emailPasswordLink($request, $user, "User created — an email is on its way to {$user->email} to set their password.");
    }

    /**
     * Deactivates $user's account (User::isDeactivated()): they can't sign
     * in, they're signed out of any session they have, and they drop out of
     * every list people are picked from — their name stays on everything
     * they did. Data Entry parts given to them by name and not yet started go
     * back to anyone in Data Entry, and nobody's parts go to them by default
     * any more. What they still hold is said, to hand on. Admin only, never
     * themselves.
     */
    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin->hasRole('Admin'), 403, 'Only an Admin can deactivate an account.');

        if ($admin->is($user)) {
            return back()->with('error', 'You can\'t deactivate your own account.');
        }

        if ($user->isDeactivated()) {
            return back()->with('error', "{$user->name}'s account is already deactivated.");
        }

        $handedBack = DB::transaction(function () use ($user, $admin) {
            $user->forceFill(['deactivated_at' => now(), 'deactivated_by' => $admin->id])->save();
            User::query()->where('default_data_entry_user_id', $user->id)->update(['default_data_entry_user_id' => null]);
            DB::table('sessions')->where('user_id', $user->id)->delete();

            return DB::table('rfq_user')
                ->where('data_entry_assigned_to', $user->id)
                ->whereNull('data_entry_started_at')
                ->whereNull('data_entry_completed_at')
                ->update(['data_entry_assigned_to' => null]);
        });

        $holding = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->whereNull('rfq_user.status')
            ->where(fn ($theirs) => $theirs
                ->where(fn ($sourcing) => $sourcing->where('rfq_user.user_id', $user->id)->whereNull('rfq_user.finalized_at'))
                ->orWhere(fn ($dataEntry) => $dataEntry->where('rfq_user.data_entry_started_by', $user->id)->whereNull('rfq_user.data_entry_completed_at')))
            ->count();

        Log::warning('An Admin deactivated an account.', ['admin_id' => $admin->id, 'user_id' => $user->id, 'user' => $user->email]);

        return back()->with('status', "Deactivated {$user->name} — they can't sign in any more."
            .($handedBack > 0 ? ' '.trans_choice('1 Data Entry part given to them went back to anyone in Data Entry.|:count Data Entry parts given to them went back to anyone in Data Entry.', $handedBack, ['count' => $handedBack]) : '')
            .($holding > 0 ? ' '.trans_choice('They still hold 1 open part — hand it on from its RFQ.|They still hold :count open parts — hand them on from their RFQs.', $holding, ['count' => $holding]) : ''));
    }

    /**
     * Lets a deactivated account sign in again, and be picked for work.
     * Admin only.
     */
    public function activate(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can activate an account.');

        if (! $user->isDeactivated()) {
            return back()->with('error', "{$user->name}'s account is already active.");
        }

        $user->forceFill(['deactivated_at' => null, 'deactivated_by' => null])->save();

        Log::info('An Admin activated an account.', ['admin_id' => $request->user()->id, 'user_id' => $user->id, 'user' => $user->email]);

        return back()->with('status', "Activated {$user->name} — they can sign in again.");
    }

    /**
     * Emails $user a fresh link to set their password — for a link that
     * expired or went astray. Any link sent before stops working.
     */
    public function sendPasswordLink(Request $request, User $user): RedirectResponse
    {
        $this->abortUnlessCanManage($request->user(), $user);

        return $this->emailPasswordLink($request, $user, "A fresh link to set their password is on its way to {$user->email}.");
    }

    /**
     * Sends AccountCreated to $user with a new "new_users" token, then back
     * to the list — or their own page — saying $sent, or, if the mail
     * couldn't go, why not.
     */
    private function emailPasswordLink(Request $request, User $user, string $sent): RedirectResponse
    {
        try {
            $user->notify(new AccountCreated(Password::broker('new_users')->createToken($user), $request->user()));
        } catch (Throwable $exception) {
            report($exception);

            return $this->backFrom($request, $user)
                ->with('error', "The email to {$user->email} couldn't be sent — check the mail settings, then use Send link to try again.");
        }

        return $this->backFrom($request, $user)->with('status', $sent);
    }

    /**
     * Changes someone's details and roles — Admin, or HR Manager (who holds
     * users.edit too). Only an Admin changes an Admin's, or sets anyone's
     * password: HR Manager sends a link to set it instead.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->abortUnlessCanManage($request->user(), $user);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => $request->user()->hasRole('Admin') ? ['nullable', 'string', 'min:8', 'confirmed'] : ['prohibited'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::in($this->assignableRoles($request->user())->pluck('name'))],
        ], [
            'password.prohibited' => 'Only an Admin can set someone\'s password — send them a link instead.',
            'roles.*.in' => 'Pick from the roles listed.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator, 'edit')->withInput();
        }

        $validated = $validator->validated();

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();
        $user->syncRoles($validated['roles'] ?? []);

        return $this->backFrom($request, $user)->with('status', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User deleted successfully.');
    }
}
