<?php

use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\ExtraWorkingHour;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountCreated;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // Wednesday 7 October, midday in Colombo.
    test()->travelTo(Carbon::parse('2026-10-07 06:30:00'));

    foreach (['users.view', 'users.create', 'users.edit', 'users.delete'] as $permission) {
        Permission::findOrCreate($permission);
    }

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Manager'] as $role) {
        Role::findOrCreate($role);
    }

    Role::findByName('Admin')->givePermissionTo(Permission::all());
    Role::findByName('HR Manager')->givePermissionTo(['users.view', 'users.create', 'users.edit']);
});

/**
 * An Admin, who can open RFQs too.
 */
function detailAdmin(): User
{
    return userWithRole('Admin')->givePermissionTo(Permission::all());
}

/**
 * $user's page, as $viewer (an Admin by default).
 */
function userPage(User $user, ?User $viewer = null): TestResponse
{
    return test()->actingAs($viewer ?? detailAdmin())->get(route('admin.users.show', $user))->assertOk();
}

// ---- getting there ---------------------------------------------------------

it('opens each person\'s page from the Users list', function () {
    $riley = userWithRole('Sourcing');

    test()->actingAs(detailAdmin())->get(route('admin.users.index'))->assertOk()
        ->assertSee('href="'.route('admin.users.show', $riley).'" class="user-name-link"', false)
        // And from their Options menu.
        ->assertSee('<a class="dropdown-item" href="'.route('admin.users.show', $riley).'">', false)
        ->assertSee('View details');

    userPage($riley)
        ->assertSee('<h2 class="user-profile-name">'.e($riley->name).'</h2>', false)
        ->assertSee($riley->email)
        ->assertSee('<span class="badge badge-soft-primary">Sourcing</span>', false)
        ->assertSee('Joined Oct 07, 2026')
        ->assertSee('<a href="'.route('admin.users.index').'">Users</a>', false)
        ->assertSee('href="'.route('admin.messages.show', $riley).'"', false);
});

it('is for those who can see the Users list — Admin and HR Manager — and nobody else', function () {
    $riley = userWithRole('Sourcing');

    userPage($riley, User::factory()->create()->assignRole('HR Manager'));

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.users.show', $riley))->assertForbidden();
    test()->actingAs($riley)->get(route('admin.users.show', $riley))->assertForbidden();
});

it('says it\'s you on your own page, with nobody to message', function () {
    $admin = detailAdmin();

    userPage($admin, $admin)
        ->assertSee('<span class="badge badge-soft-info">You</span>', false)
        ->assertDontSee('href="'.route('admin.messages.show', $admin).'"', false);
});

// ---- their work ------------------------------------------------------------

it('shows a Sourcing member\'s work: what they\'ve sourced, what\'s in their hands, and what they did', function () {
    $riley = userWithRole('Sourcing');

    $open = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2101', 'subject' => 'Lobby lights']), [1 => $riley]);
    $done = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2102']), [1 => $riley]);
    $done->completeSourcingPart(1, 'Quotes attached');

    userPage($riley)
        ->assertSeeInOrder(['Parts sourced', '1', 'Assigned to Data Entry'])
        ->assertSeeInOrder(['Working time', 'On RFQs, last 30 days'])
        ->assertDontSee('RFQs created')
        // In their hands: the one still with them, not the one handed on.
        ->assertSeeInOrder(['id="user-pane-in-hand"', 'RFQ2101', 'Lobby lights', 'In progress', 'id="user-pane-activity"'], false)
        ->assertSee('href="'.route('admin.rfqs.show', $open).'?status=Pending"', false)
        // What they did, linking to the RFQ.
        ->assertSeeInOrder(['id="user-pane-activity"', 'Assigned to Data Entry <strong>RFQ2102</strong>'], false)
        ->assertSee('href="'.route('admin.rfqs.show', $done).'" class="today-feed-item tone-success"', false);
});

it('shows Business Development the RFQs they\'ve created and closed — and no attendance, their time isn\'t tracked', function () {
    $bd = userWithRole('Business Development');
    Rfq::factory()->create(['rfq_number' => 'RFQ2201', 'created_by' => $bd->id]);
    Rfq::factory()->create(['rfq_number' => 'RFQ2202', 'created_by' => $bd->id, 'status' => 'Completed', 'stage' => 'closed', 'bd_closed_by' => $bd->id, 'bd_closed_at' => now()]);

    userPage($bd)
        ->assertSeeInOrder(['RFQs created', '2'])
        ->assertSeeInOrder(['RFQs closed', '1'])
        ->assertDontSee('Working time')
        ->assertDontSee('Parts sourced')
        ->assertSee('Created <strong>RFQ2202</strong>', false)
        ->assertDontSee('id="user-tab-attendance"', false)
        ->assertDontSee('id="user-tab-extra-hours"', false)
        ->assertDontSee('id="user-tab-in-hand"', false);
});

it('shows Data Entry the part they\'ve started', function () {
    $dataEntry = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2301']), [1 => userWithRole('Sourcing')]);
    $rfq->completeSourcingPart(1);
    startDataEntryOn($rfq->refresh(), 1, $dataEntry);

    userPage($dataEntry)
        ->assertSeeInOrder(['Sent to finalize', '0', 'Parts entered'])
        ->assertSeeInOrder(['id="user-pane-in-hand"', 'RFQ2301', 'Data Entry started'], false);
});

it('says so when they\'ve done nothing on an RFQ yet', function () {
    userPage(userWithRole('Sourcing'))
        ->assertSee('Nothing done on any RFQ yet.')
        ->assertSee('Nothing in their hands right now.');
});

it('names the RFQs to HR Manager without linking into them — they can\'t open RFQs', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2401']), [1 => $riley]);
    $rfq->completeSourcingPart(1, 'Quotes attached');
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2402']), [1 => $riley]);

    $html = userPage($riley, User::factory()->create()->assignRole('HR Manager'))->getContent();

    expect($html)->toContain('RFQ2401')
        ->toContain('RFQ2402')
        ->not->toContain(route('admin.rfqs.show', $rfq));
});

// ---- attendance and extra hours ---------------------------------------------

it('lists the working days of the last two weeks\' attendance, as HR Manager approved it', function () {
    $riley = userWithRole('Sourcing');
    Setting::put('attendance_since', '2026-09-28');

    $sheetOn = fn (string $date, bool $approved = true) => AttendanceSheet::factory()->create(['date' => $date, 'approved_at' => $approved ? now() : null]);
    Attendance::factory()->for($sheetOn('2026-10-05'), 'sheet')->create(['user_id' => $riley->id]);
    Attendance::factory()->absent()->for($sheetOn('2026-10-06'), 'sheet')->create(['user_id' => $riley->id]);
    // Marked, but not approved yet.
    Attendance::factory()->for($sheetOn('2026-10-02', approved: false), 'sheet')->create(['user_id' => $riley->id]);

    userPage($riley)
        ->assertSeeInOrder([
            'Wed, Oct 7', 'Not marked yet',
            'Tue, Oct 6', 'On leave',
            'Mon, Oct 5', 'Present',
            'Fri, Oct 2', 'Not marked yet',
            'Mon, Sep 28',
        ])
        // No weekend, and nothing from before attendance started.
        ->assertDontSee('Sun, Oct 4')
        ->assertDontSee('Fri, Sep 25');
});

it('says so while attendance isn\'t kept', function () {
    userPage(userWithRole('Data Entry'))
        ->assertSee('id="user-tab-attendance"', false)
        ->assertSee('Attendance isn\'t being kept yet', false);
});

it('shows the extra hours still counting for them, with Admin\'s window to change them', function () {
    $riley = userWithRole('Sourcing');
    ExtraWorkingHour::factory()->create(['user_id' => $riley->id, 'start' => '19:00', 'end' => '20:30']);
    ExtraWorkingHour::factory()->create(['user_id' => $riley->id, 'start' => '06:00', 'end' => '07:00', 'ended_at' => now()]);

    userPage($riley)
        ->assertSee('19:00 – 20:30')
        ->assertSee('Mon–Fri · from Jan 01, 2026')
        ->assertDontSee('06:00 – 07:00')
        ->assertSee('id="extraHoursModal-'.$riley->id.'"', false);

    // HR Manager sees them, without the window.
    userPage($riley, User::factory()->create()->assignRole('HR Manager'))
        ->assertSee('19:00 – 20:30')
        ->assertDontSee('id="extraHoursModal-'.$riley->id.'"', false);
});

// ---- the actions, coming back here -----------------------------------------

it('comes back to the page after an edit made there, and to the list after one made on the list', function () {
    $riley = userWithRole('Sourcing');
    $payload = ['name' => 'Riley Jay', 'email' => $riley->email, 'roles' => ['Sourcing']];

    test()->actingAs(detailAdmin())->put(route('admin.users.update', $riley), $payload + ['return_to' => 'show'])
        ->assertRedirect(route('admin.users.show', $riley))
        ->assertSessionHas('status', 'User updated successfully.');

    test()->actingAs(detailAdmin())->put(route('admin.users.update', $riley), $payload)
        ->assertRedirect(route('admin.users.index'));

    expect($riley->refresh()->name)->toBe('Riley Jay');

    userPage($riley)
        ->assertSee('<input type="hidden" name="return_to" value="show">', false)
        ->assertSee('data-action="'.route('admin.users.update', $riley).'"', false);
});

it('comes back to the page after sending a password link from there', function () {
    Notification::fake();
    $riley = userWithRole('Sourcing');

    test()->actingAs(detailAdmin())->post(route('admin.users.password-link', $riley), ['return_to' => 'show'])
        ->assertRedirect(route('admin.users.show', $riley));

    Notification::assertSentTo($riley, AccountCreated::class);
});

it('keeps an Admin\'s Edit and password link to Admins, as the list does', function () {
    $admin = detailAdmin();

    userPage($admin, User::factory()->create()->assignRole('HR Manager'))
        ->assertDontSee('js-edit-user', false)
        ->assertDontSee(route('admin.users.password-link', $admin))
        ->assertSee('href="'.route('admin.messages.show', $admin).'"', false);
});

// ---- the tabs --------------------------------------------------------------

it('splits the page into tabs — the ones that fit the person', function () {
    $tabsOf = fn (User $user) => preg_match_all('/data-user-tab="([a-z-]+)"/', userPage($user)->getContent(), $matches) ? $matches[1] : [];

    expect($tabsOf(userWithRole('Sourcing')))->toBe(['overview', 'in-hand', 'activity', 'attendance', 'working-hours', 'extra-hours'])
        ->and($tabsOf(userWithRole('Business Development')))->toBe(['overview', 'activity', 'working-hours'])
        ->and($tabsOf(userWithRole('GM Assistant')))->toBe(['overview', 'in-hand', 'activity', 'attendance', 'working-hours', 'extra-hours']);
});

it('opens on Overview — its figures and details — or the tab in the address', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2501']), [1 => $riley]);
    $rfq->completeSourcingPart(1, 'Quotes attached');

    userPage($riley)
        ->assertSee('class="nav-link active" id="user-tab-overview"', false)
        ->assertSee('class="tab-pane fade show active" id="user-pane-overview"', false)
        ->assertSeeInOrder(['<dt>Last step taken</dt>', 'Assigned to Data Entry RFQ2501'], false);

    test()->actingAs(detailAdmin())->get(route('admin.users.show', [$riley, 'tab' => 'attendance']))->assertOk()
        ->assertSee('class="nav-link active" id="user-tab-attendance"', false)
        ->assertSee('class="tab-pane fade show active" id="user-pane-attendance"', false)
        ->assertDontSee('class="tab-pane fade show active" id="user-pane-overview"', false);

    // A tab they haven't got is Overview.
    test()->actingAs(detailAdmin())->get(route('admin.users.show', [userWithRole('Business Development'), 'tab' => 'attendance']))->assertOk()
        ->assertSee('class="nav-link active" id="user-tab-overview"', false);
});

it('counts the parts in their hands and their extra hours on the tabs', function () {
    $riley = userWithRole('Sourcing');
    splitAmong(Rfq::factory()->create(), [1 => $riley]);
    splitAmong(Rfq::factory()->create(), [1 => $riley]);
    ExtraWorkingHour::factory()->create(['user_id' => $riley->id]);

    userPage($riley)
        ->assertSeeInOrder(['id="user-tab-in-hand"', 'In their hands', '<span class="rfq-tab-count">2</span>'], false)
        ->assertSeeInOrder(['id="user-tab-extra-hours"', 'Extra hours', '<span class="rfq-tab-count">1</span>'], false);
});
