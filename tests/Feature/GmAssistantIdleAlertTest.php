<?php

use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\GmAssistantIdle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * A moment on Monday 5 October in Colombo — 08:30–17:00, lunch 12:30–13:30.
 */
function gmaIdleClock(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE);
}

/**
 * $number, its one part approved on to GM Assistant by the Head of Business
 * Development at $at ("H:i").
 */
function approvedToGmAssistantAt(string $at, string $number = 'RFQ41001'): Rfq
{
    test()->travelTo(gmaIdleClock($at)->subMinutes(30));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number]), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $dataEntry = userWithRole('Data Entry');
    startDataEntryOn($rfq, 1, $dataEntry);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry, 'Entered', 2);
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));

    test()->travelTo(gmaIdleClock($at));
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));

    return $rfq->refresh();
}

/**
 * Runs GM Assistant's idle check at $at ("H:i").
 */
function checkGmaIdleAt(string $at): void
{
    test()->travelTo(gmaIdleClock($at));
    test()->artisan('rfq:alert-idle-gm-assistant')->assertSuccessful();
}

/**
 * The GM Assistant idle alerts $user has, oldest first, as they read.
 *
 * @return array<int, string>
 */
function gmaIdleAlertsOf(User $user): array
{
    return $user->notifications()->where('type', GmAssistantIdle::class)->oldest()->get()
        ->map(fn (DatabaseNotification $alert) => $alert->data['message'])
        ->all();
}

/**
 * Casey in GM Assistant, with Senior Operations and Admin to hear about it.
 *
 * @return array{0: User, 1: User, 2: User}
 */
function gmaIdleCast(): array
{
    $casey = tap(userWithRole('GM Assistant'))->update(['name' => 'Casey']);

    return [$casey, userWithRole('Senior Operations'), userWithRole('Admin')];
}

// ---- The setting -------------------------------------------------------------------

it('starts on, at 10 working minutes, and lets an Admin change it — apart from Data Entry\'s', function () {
    expect(Setting::gmAssistantIdleAlert())->toBe(['enabled' => true, 'minutes' => 10]);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit', ['tab' => 'idle-alert']))->assertOk()
        ->assertSee('Idle Alert — Data Entry')
        ->assertSee('Idle Alert — GM Assistant')
        ->assertSee('id="gma-idle-alert-minutes"', false)
        ->assertSee('action="'.route('admin.settings.gm-assistant-idle-alert').'"', false);

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.settings.gm-assistant-idle-alert'), ['enabled' => '0', 'minutes' => 25])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'GM Assistant idle alert saved.')
        ->assertSessionHas('settings_tab', 'idle-alert');

    expect(Setting::gmAssistantIdleAlert())->toBe(['enabled' => false, 'minutes' => 25])
        ->and(Setting::dataEntryIdleAlert())->toBe(['enabled' => true, 'minutes' => 10]);
});

it('keeps the minutes sensible, and the setting Admin\'s', function () {
    test()->actingAs(userWithRole('Admin'))->patch(route('admin.settings.gm-assistant-idle-alert'), ['enabled' => '1', 'minutes' => 0])
        ->assertSessionHasErrorsIn('gm_assistant_idle_alert', ['minutes' => 'Keep it between 1 and 480 minutes.']);

    test()->actingAs(userWithRole('Senior Operations'))->patch(route('admin.settings.gm-assistant-idle-alert'), ['enabled' => '1', 'minutes' => 5])
        ->assertForbidden();

    expect(Setting::gmAssistantIdleAlert())->toBe(['enabled' => true, 'minutes' => 10]);
});

it('runs every minute', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');

    expect($commands)->toContain('rfq:alert-idle-gm-assistant');
});

// ---- The alert -----------------------------------------------------------------------

it('tells Senior Operations and Admin once GM Assistant has had a part waiting and started nothing for 10 working minutes', function () {
    [$casey, $ops, $admin] = gmaIdleCast();
    approvedToGmAssistantAt('09:00');

    checkGmaIdleAt('09:09');
    expect(gmaIdleAlertsOf($ops))->toBe([]);

    checkGmaIdleAt('09:10');
    expect(gmaIdleAlertsOf($ops))->toBe(['Casey (GM Assistant) hasn\'t started anything for 10m — 1 part is waiting.'])
        ->and(gmaIdleAlertsOf($admin))->toHaveCount(1)
        ->and(gmaIdleAlertsOf($casey))->toBe([]);

    // Once a stretch, not every minute.
    checkGmaIdleAt('09:30');
    expect(gmaIdleAlertsOf($ops))->toHaveCount(1);
});

it('says nothing while they\'ve a part running, and counts afresh from a pause', function () {
    [$casey, $ops] = gmaIdleCast();
    $first = approvedToGmAssistantAt('09:00', 'RFQ41001');
    approvedToGmAssistantAt('09:00', 'RFQ41002');

    test()->travelTo(gmaIdleClock('09:05'));
    startGmAssistantOn($first, 1, $casey);

    checkGmaIdleAt('10:00');
    expect(gmaIdleAlertsOf($ops))->toBe([]);

    // Paused at 10:00 — free from then, with RFQ41002 waiting.
    $first->refresh()->pauseGmAssistantPart(1, $casey, 'Waiting on the GM');
    checkGmaIdleAt('10:09');
    expect(gmaIdleAlertsOf($ops))->toBe([]);

    checkGmaIdleAt('10:10');
    expect(gmaIdleAlertsOf($ops))->toBe(['Casey (GM Assistant) hasn\'t started anything for 10m — 1 part is waiting.']);
});

it('says nothing with nothing waiting, the alert off, out of hours, or them on leave', function (Closure $setUp, string $at) {
    [$casey, $ops] = gmaIdleCast();
    $setUp($casey);

    checkGmaIdleAt($at);

    expect(gmaIdleAlertsOf($ops))->toBe([]);
})->with([
    'nothing waiting' => [fn () => null, '10:00'],
    'the alert off' => [function () {
        approvedToGmAssistantAt('09:00');
        Setting::putGmAssistantIdleAlert(['enabled' => false, 'minutes' => 10]);
    }, '10:00'],
    'at lunch' => [fn () => approvedToGmAssistantAt('12:20'), '12:45'],
    'on leave' => [function (User $casey) {
        approvedToGmAssistantAt('09:00');
        Attendance::factory()->absent()->for(AttendanceSheet::factory()->state(['date' => '2026-10-05']), 'sheet')->create(['user_id' => $casey->id]);
    }, '10:00'],
]);

it('lists in the bell and opens Casey\'s conversation, to nudge them', function () {
    [$casey, $ops] = gmaIdleCast();
    approvedToGmAssistantAt('09:00');
    checkGmaIdleAt('09:10');

    test()->actingAs($ops)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('Casey (GM Assistant) hasn&#039;t started anything for 10m', false);

    test()->actingAs($ops)->get(route('admin.notifications.open', $ops->notifications()->sole()->id))
        ->assertRedirect(route('admin.messages.show', $casey));
});

it('counts down on their Review page, then says they\'ve been told', function () {
    [$casey] = gmaIdleCast();
    approvedToGmAssistantAt('09:00');

    test()->travelTo(gmaIdleClock('09:04'));
    test()->actingAs($casey)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('You haven\'t started anything yet — 1 part is waiting', false)
        ->assertSee('data-remaining="360" data-alert-after="600"', false);

    checkGmaIdleAt('09:10');

    test()->actingAs($casey)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Senior Operations has been told you haven\'t started', false);
});
