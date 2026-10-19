<?php

use App\Models\ExtraWorkingHour;
use App\Models\Rfq;
use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }

    // As the app has it: Admin holds every permission.
    Role::findByName('Admin')->givePermissionTo(collect(['users.view', 'users.create', 'users.edit', 'users.delete'])
        ->map(fn (string $name) => Permission::findOrCreate($name)));
});

/**
 * A moment in Colombo — the working hours' own time zone; 2026-10-05 is a
 * Monday: 08:30–17:00, lunch 12:30–13:30.
 */
function extraClock(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * $user works $start–$end on weekdays too, counting from $from.
 *
 * @param  array<string, mixed>  $state
 */
function worksExtra(User $user, string $start = '19:00', string $end = '20:00', string $from = '2026-10-01', array $state = []): ExtraWorkingHour
{
    return ExtraWorkingHour::factory()->create(['user_id' => $user->id, 'start' => $start, 'end' => $end, 'starts_on' => $from] + $state);
}

it('adds someone\'s extra hours to their working time — theirs alone', function () {
    $riley = userWithRole('Sourcing');
    $other = userWithRole('Sourcing');
    worksExtra($riley);

    $monday = [extraClock('2026-10-05 00:00'), extraClock('2026-10-06 00:00')];

    // 08:30–12:30 and 13:30–17:00 for everyone; 19:00–20:00 for Riley too.
    expect(Setting::workingSecondsBetween(...$monday))->toBe(27000)
        ->and(Setting::workingSecondsBetween(...[...$monday, $other->id]))->toBe(27000)
        ->and(Setting::workingSecondsBetween(...[...$monday, $riley->id]))->toBe(30600)
        ->and(Setting::isWorkingTime(extraClock('2026-10-05 19:30'), $riley->id))->toBeTrue()
        ->and(Setting::isWorkingTime(extraClock('2026-10-05 19:30')))->toBeFalse()
        // Weekdays only, as given: not Saturday.
        ->and(Setting::isWorkingTime(extraClock('2026-10-10 19:30'), $riley->id))->toBeFalse();
});

it('merges extra hours that meet or overlap the working hours, counting nothing twice', function () {
    $riley = userWithRole('Data Entry');
    worksExtra($riley, '12:00', '14:00');

    // Through lunch: 08:30–17:00 straight.
    expect(Setting::workingPeriodsBetween(extraClock('2026-10-05 00:00'), extraClock('2026-10-06 00:00'), $riley->id))
        ->toBe([[extraClock('2026-10-05 08:30')->getTimestamp(), extraClock('2026-10-05 17:00')->getTimestamp()]]);
});

it('counts extra hours from the day they count from, and until they were taken away', function () {
    $riley = userWithRole('Sourcing');
    worksExtra($riley, from: '2026-10-06', state: ['ended_at' => extraClock('2026-10-07 19:30')->utc()]);
    $evening = fn (string $date) => Setting::workingSecondsBetween(extraClock("{$date} 18:00"), extraClock("{$date} 21:00"), $riley->id);

    expect($evening('2026-10-05'))->toBe(0)
        ->and($evening('2026-10-06'))->toBe(3600)
        ->and($evening('2026-10-07'))->toBe(1800)
        ->and($evening('2026-10-08'))->toBe(0);
});

it('times someone\'s stretches by their own hours — and work in them isn\'t out of hours', function () {
    $riley = userWithRole('Sourcing');
    worksExtra($riley);

    $stretch = RfqStep::factory()->create([
        'step' => 'sourcing', 'assignee_id' => $riley->id, 'worked_by' => $riley->id, 'ended_by_role' => true,
        'started_at' => extraClock('2026-10-05 16:00')->utc(), 'ended_at' => extraClock('2026-10-05 19:45')->utc(),
    ]);

    // 16:00–17:00, then 19:00–19:45.
    expect($stretch->workingSeconds())->toBe(6300)
        ->and($stretch->isOutOfHoursWork())->toBeFalse();

    // Someone without them, the same evening: out of hours, an hour counted.
    $other = RfqStep::factory()->create([
        'step' => 'sourcing', 'assignee_id' => userWithRole('Sourcing')->id, 'ended_by_role' => true,
        'started_at' => extraClock('2026-10-05 16:00')->utc(), 'ended_at' => extraClock('2026-10-05 19:45')->utc(),
    ]);
    expect($other->workingSeconds())->toBe(3600)
        ->and($other->isOutOfHoursWork())->toBeTrue();
});

it('counts Data Entry\'s time in their own extra hours — and says when those start', function () {
    $nadeesha = userWithRole('Data Entry');
    $chamath = userWithRole('Data Entry');
    worksExtra($nadeesha);

    test()->travelTo(extraClock('2026-10-05 08:30'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ70001']), [1 => userWithRole('Sourcing')]);
    $rfq->completeSourcingPart(1);

    // 18:30: off for both — Nadeesha told her own hours start at 19:00.
    test()->travelTo(extraClock('2026-10-05 18:30'));
    expect(Setting::offHoursReason(now(), $nadeesha->id))->toBe('Outside working hours — your extra hours start at 19:00.')
        ->and(Setting::offHoursReason(now()))->toStartWith('Working hours are over for today');

    test()->travelTo(extraClock('2026-10-05 19:30'));
    $rowFor = fn ($viewer) => Str::betweenFirst(test()->actingAs($viewer)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent(), '<td class="text-nowrap">RFQ70001</td>', '</tr>');

    // Chamath could start it too, but his time wouldn't count — not his hours.
    expect($rowFor($chamath))->toContain('your time on it counts once they&#039;re back.')
        ->and($rowFor($nadeesha))->not->toMatch('/js-start-button" disabled/')
        ->not->toContain('your time on it counts once they&#039;re back.');

    test()->actingAs($nadeesha)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])
        ->assertSessionHas('status', 'Started RFQ70001 — your time on it is counting.');
});

it('sends the page each person\'s own hours, for their countdowns to tick by', function () {
    $riley = userWithRole('Sourcing');
    worksExtra($riley);
    test()->travelTo(extraClock('2026-10-05 10:00'));
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ70002']), [1 => $riley]);

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();
    $schedule = json_decode(Str::betweenFirst($html, '<script type="application/json" id="countdown-schedule">', '</script>'), true);

    expect($schedule['people'])->toHaveKey((string) $riley->id)
        ->and(collect($schedule['people'][$riley->id])->contains([extraClock('2026-10-05 19:00')->getTimestamp(), extraClock('2026-10-05 20:00')->getTimestamp()]))->toBeTrue()
        ->and($html)->toContain('data-worker="'.$riley->id.'"');
});

// ---- Settings → Extra Hours ---------------------------------------------------------

it('lets an Admin give someone extra hours, and take them away from now on', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    $riley->update(['name' => 'Riley Chen']);
    test()->travelTo(extraClock('2026-10-08 10:00'));

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'extra-hours']))->assertOk()
        ->assertSee('Nobody has extra hours')
        ->assertSee('value="'.$riley->id.'"', false);

    test()->actingAs($admin)->post(route('admin.settings.extra-hours.store'), [
        'user_id' => $riley->id, 'days' => ['monday', 'wednesday', 'friday'], 'start' => '19:00', 'end' => '20:00', 'starts_on' => '2026-10-05',
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Extra hours added for Riley Chen: Mon, Wed, Fri, 19:00–20:00.');

    $range = ExtraWorkingHour::query()->sole();
    expect($range->days)->toBe(['monday', 'wednesday', 'friday'])
        ->and($range->created_by)->toBe($admin->id)
        ->and(Setting::isWorkingTime(extraClock('2026-10-05 19:30'), $riley->id))->toBeTrue();

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'extra-hours']))
        ->assertSee('Riley Chen')
        ->assertSee('19:00 – 20:00');

    // Taken away: from now — Monday evening still counts.
    test()->actingAs($admin)->delete(route('admin.settings.extra-hours.destroy', $range))
        ->assertSessionHas('status', 'Extra hours removed for Riley Chen — from now on; time already worked in them still counts.');

    expect($range->refresh()->ended_at)->not->toBeNull()
        ->and(Setting::isWorkingTime(extraClock('2026-10-05 19:30'), $riley->id))->toBeTrue()
        ->and(Setting::isWorkingTime(extraClock('2026-10-09 19:30'), $riley->id))->toBeFalse();
    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'extra-hours']))->assertSee('Nobody has extra hours');
});

it('refuses extra hours that don\'t make sense, and keeps them to an Admin', function (array $input, string $field, string $message) {
    $riley = userWithRole('Sourcing');

    test()->actingAs(userWithRole('Admin'))->post(route('admin.settings.extra-hours.store'), $input + [
        'user_id' => $riley->id, 'days' => ['monday'], 'start' => '19:00', 'end' => '20:00', 'starts_on' => '2026-10-05',
    ])->assertSessionHasErrorsIn('extra_hours', [$field => $message]);

    expect(ExtraWorkingHour::query()->count())->toBe(0);
})->with([
    'ends before it starts' => [['start' => '21:00', 'end' => '20:00'], 'end', 'It has to end after it starts, the same day — one that runs past midnight is two ranges.'],
    'no days' => [['days' => []], 'days', 'Pick at least one day.'],
    'not someone whose time is tracked' => [['user_id' => 999999], 'user_id', 'Extra hours are for Sourcing, Data Entry and GM Assistant people.'],
]);

it('keeps people\'s working hours to an Admin', function () {
    $riley = userWithRole('Sourcing');

    foreach (['HR Executive', 'Senior Operations', 'Sourcing'] as $role) {
        test()->actingAs(userWithRole($role))->post(route('admin.settings.extra-hours.store'), [
            'user_id' => $riley->id, 'days' => ['monday'], 'start' => '19:00', 'end' => '20:00', 'starts_on' => '2026-10-05',
        ])->assertForbidden();
    }

    $range = worksExtra($riley);
    test()->actingAs(userWithRole('HR Executive'))->delete(route('admin.settings.extra-hours.destroy', $range))->assertForbidden();
    expect($range->refresh()->ended_at)->toBeNull();
});

// ---- The Users page -------------------------------------------------------------------

it('shows each tracked person\'s extra hours on the Users page, with an Extra hours window for Admin', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    $bd = userWithRole('Business Development');
    worksExtra($riley);

    $html = test()->actingAs($admin)->get(route('admin.users.index'))->assertOk()->getContent();
    $rileyRow = Str::betweenFirst($html, '<td>'.$riley->email.'</td>', '</tr>');

    expect($html)->toContain('Mon–Fri 19:00–20:00')
        ->toContain('data-bs-target="#extraHoursModal-'.$riley->id.'"')
        ->toContain('id="extraHoursModal-'.$riley->id.'"')
        ->toContain('action="'.route('admin.settings.extra-hours.store').'"')
        // Not for someone whose time isn't tracked.
        ->not->toContain('id="extraHoursModal-'.$bd->id.'"');

    // HR Executive sees the hours, not the window.
    Role::findOrCreate('HR Executive')->givePermissionTo('users.view');
    expect(test()->actingAs(userWithRole('HR Executive'))->get(route('admin.users.index'))->assertOk()->getContent())
        ->toContain('Mon–Fri 19:00–20:00')
        ->not->toContain('id="extraHoursModal-');
});

it('adds and removes extra hours from the Users page, coming back to it', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Data Entry');
    $riley->update(['name' => 'Riley Chen']);
    $usersPage = route('admin.users.index', ['search' => 'Riley']);

    test()->actingAs($admin)->from($usersPage)->post(route('admin.settings.extra-hours.store'), [
        'user_id' => $riley->id, 'return_to' => 'users', 'days' => ['saturday'], 'start' => '09:00', 'end' => '12:00', 'starts_on' => '2026-10-05',
    ])->assertRedirect($usersPage)
        ->assertSessionHas('status', 'Extra hours added for Riley Chen: Sat, 09:00–12:00.');

    $range = ExtraWorkingHour::query()->sole();
    test()->actingAs($admin)->from($usersPage)->delete(route('admin.settings.extra-hours.destroy', $range), ['return_to' => 'users'])
        ->assertRedirect($usersPage);
    expect($range->refresh()->ended_at)->not->toBeNull();

    // One that doesn't go in: back to the page, the window open again.
    $html = test()->actingAs($admin)->from($usersPage)->followingRedirects()->post(route('admin.settings.extra-hours.store'), [
        'user_id' => $riley->id, 'return_to' => 'users', 'days' => ['monday'], 'start' => '20:00', 'end' => '19:00', 'starts_on' => '2026-10-05',
    ])->getContent();

    expect($html)->toContain('document.getElementById("extraHoursModal-'.$riley->id.'")')
        ->toContain('It has to end after it starts');
});
