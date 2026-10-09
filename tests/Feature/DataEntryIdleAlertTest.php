<?php

use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DataEntryIdle;
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
 * A moment in Colombo — the working hours' own time zone; 2026-10-05 is a
 * Monday: 08:30–17:00, lunch 12:30–13:30.
 */
function idleClock(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * $rfqNumber, its one part handed to Data Entry by Sourcing at $at.
 */
function handedOverAt(string $at, string $rfqNumber = 'RFQ40001'): Rfq
{
    test()->travelTo(idleClock($at)->subMinutes(30));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $rfqNumber]), [1 => userWithRole('Sourcing')]);

    test()->travelTo(idleClock($at));
    $rfq->refresh()->completeSourcingPart(1);

    return $rfq->refresh();
}

/**
 * Runs the idle check at $at.
 */
function checkIdleAt(string $at): void
{
    test()->travelTo(idleClock($at));
    test()->artisan('rfq:alert-idle-data-entry')->assertSuccessful();
}

/**
 * The idle alerts $user has, oldest first, as they read.
 *
 * @return array<int, string>
 */
function idleAlertsOf(User $user): array
{
    return $user->notifications()->where('type', DataEntryIdle::class)->oldest()->get()
        ->map(fn (DatabaseNotification $alert) => $alert->data['message'])
        ->all();
}

/**
 * Morgan in Data Entry, with Senior Operations and Admin to hear about it.
 *
 * @return array{0: User, 1: User, 2: User}
 */
function idleCast(): array
{
    $morgan = userWithRole('Data Entry');
    $morgan->update(['name' => 'Morgan']);

    return [$morgan, userWithRole('Senior Operations'), userWithRole('Admin')];
}

// ---- The setting -----------------------------------------------------------------

it('starts on, at 10 working minutes, and lets an Admin change it', function () {
    expect(Setting::dataEntryIdleAlert())->toBe(['enabled' => true, 'minutes' => 10]);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit', ['tab' => 'idle-alert']))->assertOk()
        ->assertSee('Idle Alert')
        ->assertSee('id="idle-alert-minutes"', false);

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.settings.idle-alert'), ['enabled' => '0', 'minutes' => 25])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Idle alert saved.');

    expect(Setting::dataEntryIdleAlert())->toBe(['enabled' => false, 'minutes' => 25]);
});

it('refuses minutes out of range, and keeps the setting to an Admin', function () {
    test()->actingAs(userWithRole('Admin'))->patch(route('admin.settings.idle-alert'), ['enabled' => '1', 'minutes' => 0])
        ->assertSessionHasErrorsIn('idle_alert', ['minutes' => 'Keep it between 1 and 480 minutes.']);

    test()->actingAs(userWithRole('Senior Operations'))->patch(route('admin.settings.idle-alert'), ['enabled' => '1', 'minutes' => 5])
        ->assertForbidden();

    expect(Setting::dataEntryIdleAlert())->toBe(Setting::DEFAULT_DATA_ENTRY_IDLE_ALERT);
});

it('runs every minute on the scheduler', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'rfq:alert-idle-data-entry'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

// ---- The alert -------------------------------------------------------------------

it('alerts Senior Operations and Admin once Data Entry\'s been idle 10 working minutes with a part waiting — once', function () {
    [$morgan, $seniorOps, $admin] = idleCast();
    handedOverAt('2026-10-05 09:00');

    // Counted from when the part came in, not from the start of the day.
    checkIdleAt('2026-10-05 09:09');
    expect(DatabaseNotification::query()->count())->toBe(0);

    checkIdleAt('2026-10-05 09:10');
    $message = "Morgan (Data Entry) hasn't started anything for 10m — 1 part is waiting.";
    expect(idleAlertsOf($seniorOps))->toBe([$message])
        ->and(idleAlertsOf($admin))->toBe([$message])
        ->and(idleAlertsOf($morgan))->toBe([]);

    // Not again while they stay idle.
    checkIdleAt('2026-10-05 09:40');
    expect(idleAlertsOf($seniorOps))->toHaveCount(1);
});

it('stays quiet while they\'ve a part running, and counts afresh from when they\'re free again', function () {
    [$morgan, $seniorOps] = idleCast();
    $first = handedOverAt('2026-10-05 09:00');
    handedOverAt('2026-10-05 09:00', 'RFQ40002');

    test()->travelTo(idleClock('2026-10-05 09:05'));
    startDataEntryOn($first, 1, $morgan);
    checkIdleAt('2026-10-05 09:40');
    expect(idleAlertsOf($seniorOps))->toBe([]);

    // Sent to finalize at 10:00, with RFQ40002 still waiting.
    test()->travelTo(idleClock('2026-10-05 10:00'));
    $first->refresh()->completeDataEntryPart(1, $morgan, 'Entered');

    checkIdleAt('2026-10-05 10:09');
    expect(idleAlertsOf($seniorOps))->toBe([]);

    checkIdleAt('2026-10-05 10:10');
    expect(idleAlertsOf($seniorOps))->toBe(["Morgan (Data Entry) hasn't started anything for 10m — 1 part is waiting."]);
});

it('only counts working minutes — not lunch — and checks only in working time', function () {
    [, $seniorOps] = idleCast();
    handedOverAt('2026-10-05 12:25');

    // 12:25–12:30, then lunch, which doesn't count — nor does it check.
    checkIdleAt('2026-10-05 13:00');
    checkIdleAt('2026-10-05 13:34');
    expect(idleAlertsOf($seniorOps))->toBe([]);

    checkIdleAt('2026-10-05 13:35');
    expect(idleAlertsOf($seniorOps))->toHaveCount(1);
});

it('waits as long as the Admin set', function () {
    [, $seniorOps] = idleCast();
    Setting::putDataEntryIdleAlert(['enabled' => true, 'minutes' => 30]);
    handedOverAt('2026-10-05 09:00');

    checkIdleAt('2026-10-05 09:29');
    expect(idleAlertsOf($seniorOps))->toBe([]);

    checkIdleAt('2026-10-05 09:30');
    expect(idleAlertsOf($seniorOps))->toBe(["Morgan (Data Entry) hasn't started anything for 30m — 1 part is waiting."]);
});

it('stays quiet with nothing waiting, when it\'s off, or for someone on leave today', function (Closure $arrange) {
    [$morgan, $seniorOps] = idleCast();
    $arrange($morgan);

    checkIdleAt('2026-10-05 11:00');

    expect(idleAlertsOf($seniorOps))->toBe([]);
})->with([
    'nothing waiting' => [fn () => null],
    'turned off' => [function () {
        Setting::putDataEntryIdleAlert(['enabled' => false, 'minutes' => 10]);
        handedOverAt('2026-10-05 09:00');
    }],
    'on leave — on today\'s sheet, approved or not' => [function (User $morgan) {
        handedOverAt('2026-10-05 09:00');
        Attendance::factory()->absent()->for(AttendanceSheet::factory()->state(['date' => '2026-10-05']), 'sheet')->create(['user_id' => $morgan->id]);
    }],
]);

it('doesn\'t count the half of a half day they\'re off', function () {
    [$morgan, $seniorOps] = idleCast();
    handedOverAt('2026-10-05 09:00');
    Attendance::factory()->for(AttendanceSheet::factory()->state(['date' => '2026-10-05']), 'sheet')
        ->create(['user_id' => $morgan->id, 'status' => Attendance::HALF_DAY, 'half_off' => 'morning']);

    // Off the morning (08:30–12:30): nothing then — and after lunch, the
    // afternoon counts from 13:30.
    checkIdleAt('2026-10-05 11:00');
    checkIdleAt('2026-10-05 13:39');
    expect(idleAlertsOf($seniorOps))->toBe([]);

    checkIdleAt('2026-10-05 13:40');
    expect(idleAlertsOf($seniorOps))->toHaveCount(1);
});

// ---- The bell --------------------------------------------------------------------

it('rings the bell for Senior Operations — not for Data Entry — and opens on the person to nudge', function () {
    [$morgan, $seniorOps] = idleCast();
    handedOverAt('2026-10-05 09:00');
    checkIdleAt('2026-10-05 09:10');

    test()->actingAs($seniorOps)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('id="notificationBell"', false)
        ->assertSee('<span class="notification-bell-count" title="1 unread">1</span>', false)
        ->assertSee('Morgan (Data Entry) hasn&#039;t started anything for 10m — 1 part is waiting.', false);

    test()->actingAs($morgan)->get(route('admin.dashboard'))->assertOk()
        ->assertDontSee('id="notificationBell"', false);

    $alert = $seniorOps->notifications()->sole();
    test()->actingAs($seniorOps)->get(route('admin.notifications.open', $alert->id))
        ->assertRedirect(route('admin.messages.show', $morgan));

    expect($alert->refresh()->read_at)->not->toBeNull();
    test()->actingAs($seniorOps)->get(route('admin.dashboard'))->assertDontSee('notification-bell-count', false);
});

it('marks every alert read at once, and opens nobody else\'s', function () {
    [, $seniorOps, $admin] = idleCast();
    handedOverAt('2026-10-05 09:00');
    checkIdleAt('2026-10-05 09:10');

    test()->actingAs($seniorOps)->get(route('admin.notifications.open', $admin->notifications()->sole()->id))->assertNotFound();

    test()->actingAs($seniorOps)->post(route('admin.notifications.read-all'))->assertRedirect();

    expect($seniorOps->unreadNotifications()->count())->toBe(0)
        ->and($admin->unreadNotifications()->count())->toBe(1);
});

// ---- The countdown on their page ---------------------------------------------------

/**
 * $user's Ready for Data Entry page, now.
 */
function readyForDataEntryPage(User $user, array $query = []): string
{
    return test()->actingAs($user)->get(route('admin.rfqs.index', ['status' => 'Pending', ...$query]))->assertOk()->getContent();
}

it('counts down on their Ready for Data Entry page to Senior Operations being told', function () {
    [$morgan] = idleCast();
    handedOverAt('2026-10-05 09:00');
    test()->travelTo(idleClock('2026-10-05 09:04'));

    expect(readyForDataEntryPage($morgan))
        ->toContain('class="idle-banner js-idle-countdown mb-3"')
        ->toContain('data-remaining="360" data-alert-after="600"')
        ->toContain('<span class="idle-banner-clock js-idle-clock">06:00</span>')
        ->toContain('You haven\'t started anything yet — 1 part is waiting')
        ->toContain('data-state="running">Start a part before the clock runs out, or Senior Operations gets a message that you haven&#039;t started.')
        ->toContain('id="countdown-schedule"');
});

it('turns amber in its last quarter, pauses outside working hours, and says when time\'s up', function (string $handedOver, string $at, string $class, string $clock, string $state) {
    [$morgan] = idleCast();
    handedOverAt($handedOver);
    test()->travelTo(idleClock($at));

    expect(readyForDataEntryPage($morgan))
        ->toContain('class="idle-banner js-idle-countdown mb-3 '.$class.'"')
        ->toContain('<span class="idle-banner-clock js-idle-clock">'.$clock.'</span>')
        ->toContain('data-state="'.$state.'"');
})->with([
    'last quarter' => ['2026-10-05 09:00', '2026-10-05 09:08', 'is-urgent', '02:00', 'running'],
    // 12:25–12:30 counted; lunch isn't.
    'at lunch' => ['2026-10-05 12:25', '2026-10-05 13:00', 'is-paused', '05:00', 'paused'],
    'time\'s up, not sent yet' => ['2026-10-05 09:00', '2026-10-05 09:11', 'is-due', '00:00', 'due'],
]);

it('says once Senior Operations has been told', function () {
    [$morgan] = idleCast();
    handedOverAt('2026-10-05 09:00');
    checkIdleAt('2026-10-05 09:10');
    test()->travelTo(idleClock('2026-10-05 09:12'));

    expect(readyForDataEntryPage($morgan))
        ->toContain('class="idle-banner is-alerted mb-3"')
        ->toContain('Senior Operations has been told you haven\'t started')
        ->toContain('We sent them a message at 9:10 AM')
        ->not->toContain('js-idle-countdown');
});

it('shows no countdown with a part running, nothing waiting, the alert off — nor to Admin', function () {
    [$morgan, , $admin] = idleCast();

    test()->travelTo(idleClock('2026-10-05 09:30'));
    expect(readyForDataEntryPage($morgan))->not->toContain('idle-banner');

    $rfq = handedOverAt('2026-10-05 09:00');
    test()->travelTo(idleClock('2026-10-05 09:04'));
    expect(readyForDataEntryPage($admin, ['role' => 'data-entry']))->not->toContain('idle-banner');

    Setting::putDataEntryIdleAlert(['enabled' => false, 'minutes' => 10]);
    expect(readyForDataEntryPage($morgan))->not->toContain('idle-banner');

    Setting::putDataEntryIdleAlert(['enabled' => true, 'minutes' => 10]);
    startDataEntryOn($rfq, 1, $morgan);
    expect(readyForDataEntryPage($morgan))->not->toContain('idle-banner');
});

it('reads the clock to the second, by the hour from an hour up', function () {
    expect(Setting::clockLabel(582))->toBe('09:42')
        ->and(Setting::clockLabel(3900))->toBe('1:05:00')
        ->and(Setting::clockLabel(-30))->toBe('00:00');
});
