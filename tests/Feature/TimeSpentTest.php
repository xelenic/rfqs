<?php

use App\Models\Rfq;
use App\Models\RfqStep;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * A moment in the working hours' own time zone (Asia/Colombo by default) —
 * 2026-10-05 is a Monday.
 */
function colombo(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * $time's minutes for one role on $rfq, freshly loaded.
 */
function minutesFor(Rfq $rfq, string $role): int
{
    return $rfq->refresh()->load('steps')->timeSpent()['roles'][$role]['minutes'];
}

// ---- Working time ------------------------------------------------------------

it('counts only working hours: no nights, lunches or days off', function (string $from, string $to, int $minutes) {
    expect(Setting::workingMinutesBetween(colombo($from), colombo($to)))->toBe($minutes);
})->with([
    'within a morning' => ['2026-10-05 09:00', '2026-10-05 11:00', 120],
    'across lunch' => ['2026-10-05 12:00', '2026-10-05 14:00', 60],
    'before work starts' => ['2026-10-05 06:00', '2026-10-05 08:30', 0],
    'over the weekend' => ['2026-10-09 16:00', '2026-10-12 09:30', 120],
    'all weekend' => ['2026-10-10 10:00', '2026-10-11 15:00', 0],
    'a whole working day' => ['2026-10-05 00:00', '2026-10-06 00:00', 450],
    'backwards' => ['2026-10-05 11:00', '2026-10-05 09:00', 0],
]);

it('reads the hours in their own time zone, whatever zone the times come in', function () {
    // 03:00–05:00 UTC is 08:30–10:30 in Colombo.
    $from = CarbonImmutable::parse('2026-10-05 03:00', 'UTC');
    $to = CarbonImmutable::parse('2026-10-05 05:00', 'UTC');

    expect(Setting::workingMinutesBetween($from, $to))->toBe(120);

    // In UTC itself, the same stretch is before the working day.
    Setting::put('timezone', 'UTC');
    expect(Setting::workingMinutesBetween($from, $to))->toBe(0);
});

it('follows the working hours as set — a Saturday morning, a day with no lunch', function () {
    $week = Setting::workingHours();
    $week['saturday'] = ['working' => true, 'start' => '09:00', 'end' => '13:00', 'lunch_start' => null, 'lunch_end' => null];
    Setting::putWorkingHours($week);

    expect(Setting::workingMinutesBetween(colombo('2026-10-10 08:00'), colombo('2026-10-10 18:00')))->toBe(240);
});

it('saves the time zone with the working hours, and only a real one', function () {
    $payload = ['days' => collect(Setting::workingHours())->map(fn (array $day) => ['working' => $day['working'] ? '1' : '0'] + $day)->all()];

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.settings.working-hours'), $payload + ['timezone' => 'Asia/Dubai'])
        ->assertSessionHasNoErrors();
    expect(Setting::timezone())->toBe('Asia/Dubai');

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.settings.working-hours'), $payload + ['timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrorsIn('working_hours', ['timezone' => 'Choose a time zone from the list.']);
    expect(Setting::timezone())->toBe('Asia/Dubai');
});

// ---- The step log --------------------------------------------------------------

it('logs each role\'s stretch on a part as it moves along, and adds them up', function () {
    $riley = userWithRole('Sourcing');

    test()->travelTo(colombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    test()->travelTo(colombo('2026-10-05 11:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);

    // Data Entry: Monday 11:00–17:00 less lunch, and Tuesday 08:30–10:00.
    test()->travelTo(colombo('2026-10-06 10:00'));
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));

    test()->travelTo(colombo('2026-10-06 10:30'));
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));

    test()->travelTo(colombo('2026-10-06 11:00'));
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));

    // GM Assistant: 11:00–15:00 less lunch.
    test()->travelTo(colombo('2026-10-06 15:00'));
    $rfq->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'));

    expect($rfq->refresh()->steps->map(fn (RfqStep $step) => [$step->step, $step->assignee_id, $step->ended_at !== null])->all())->toBe([
        ['sourcing', $riley->id, true],
        ['data_entry', $riley->id, true],
        ['finalize', $riley->id, true],
        ['gm_assistant', $riley->id, true],
    ]);

    $time = $rfq->timeSpent();

    expect($time['roles']['Sourcing'])->toMatchArray(['minutes' => 150, 'parts' => [1 => 150], 'rounds' => 1, 'reworks' => 0, 'ongoing' => false])
        ->and($time['roles']['Data Entry']['minutes'])->toBe(390)
        ->and($time['roles']['GM Assistant']['minutes'])->toBe(180)
        ->and($time['minutes'])->toBe(720);
});

it('adds up every round of rework, both ways between Sourcing and Data Entry', function () {
    test()->travelTo(colombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $dataEntry = userWithRole('Data Entry');

    test()->travelTo(colombo('2026-10-05 10:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);

    // Data Entry sends it back to Sourcing, who redo it.
    test()->travelTo(colombo('2026-10-05 11:00'));
    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', $dataEntry);
    test()->travelTo(colombo('2026-10-05 12:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);

    // Data Entry sends it to finalize; Sourcing sends it back to them instead.
    test()->travelTo(colombo('2026-10-05 15:00'));
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);
    test()->travelTo(colombo('2026-10-05 16:00'));
    $rfq->refresh()->returnToDataEntry(1, 'Wrong currency');
    startDataEntryOn($rfq, 1);
    test()->travelTo(colombo('2026-10-05 17:00'));
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    $time = $rfq->refresh()->timeSpent(colombo('2026-10-05 17:00'));

    // Sourcing: 09–10, 11–12 and the Finalize hour 15–16 — two rounds of their work.
    expect($time['roles']['Sourcing'])->toMatchArray(['minutes' => 180, 'rounds' => 2, 'reworks' => 1, 'ongoing' => true])
        // Data Entry: 10–11, 12–15 less lunch, 16–17.
        ->and($time['roles']['Data Entry'])->toMatchArray(['minutes' => 240, 'rounds' => 3, 'reworks' => 2, 'ongoing' => false]);
});

it('counts a role still at it up to now', function () {
    test()->travelTo(colombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);

    test()->travelTo(colombo('2026-10-05 10:15'));

    expect($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray(['minutes' => 75, 'ongoing' => true]);
});

it('keeps each part\'s time on a split apart, and in all', function () {
    test()->travelTo(colombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);

    test()->travelTo(colombo('2026-10-05 10:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);
    test()->travelTo(colombo('2026-10-05 11:30'));
    $rfq->refresh()->completeSourcingPart(2);
    startDataEntryOn($rfq, 2);

    expect($rfq->refresh()->timeSpent(colombo('2026-10-05 11:30'))['roles']['Sourcing'])
        ->toMatchArray(['parts' => [1 => 60, 2 => 150], 'minutes' => 210, 'rounds' => 2, 'reworks' => 0]);
});

it('pauses GM Assistant\'s clock while the RFQ is held on the Head\'s Returns page', function () {
    $assistant = userWithRole('GM Assistant');
    $head = userWithRole('Head of Business Development');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);

    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        startDataEntryOn($rfq, $part);
        $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, userWithRole('Senior Operations'));
        $rfq->refresh()->approveHeadOfBdPart($part, $head);
    }
    $rfq->refresh()->recordGmAssistantPart(1, $assistant);

    $openGmAssistant = fn () => RfqStep::query()->where('rfq_id', $rfq->id)->where('step', 'gm_assistant')->whereNull('ended_at')->pluck('part_number')->all();
    expect($openGmAssistant())->toBe([2]);

    // The General Manager sends P1 back to the Head: P2 can't be worked meanwhile.
    $rfq->refresh()->rejectPartToStage(1, 'head_of_bd_review', 'Check P1', userWithRole('General Manager'), 'gm_review');
    expect($openGmAssistant())->toBe([]);

    $rfq->refresh()->approveHeadOfBdPart(1, $head);
    expect($openGmAssistant())->toEqualCanonicalizing([1, 2]);
});

it('ends a part\'s stretch when it\'s taken off its member, and every stretch once the RFQ is closed', function () {
    $rfq = Rfq::factory()->create();
    $rfq->planSplit(2);
    $rfq->assignSourcingParts([1 => userWithRole('Sourcing')->id]);
    expect(RfqStep::query()->whereNull('ended_at')->count())->toBe(1);

    test()->actingAs(userWithRole('Senior Operations'))
        ->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Need the site address'])
        ->assertSessionHasNoErrors();
    expect(RfqStep::query()->whereNull('ended_at')->count())->toBe(0);

    $other = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    test()->actingAs(userWithRole('Admin'))->put(route('admin.rfqs.update', $other), [
        'wc_number' => $other->wc_number, 'rfq_number' => $other->rfq_number,
        'priority_level' => 'Medium', 'number_of_items' => 5, 'status' => 'Completed', 'subject' => $other->subject,
    ])->assertSessionHasNoErrors();
    expect(RfqStep::query()->where('rfq_id', $other->id)->whereNull('ended_at')->count())->toBe(0);
});

// ---- Where it shows ------------------------------------------------------------

it('shows the time spent on the RFQ\'s page, by role and part', function () {
    test()->travelTo(colombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-05 10:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);
    test()->travelTo(colombo('2026-10-05 11:30'));

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('Time spent')
        ->assertSee('P1 1h · P2 2h 30m')
        // P1 has been with Data Entry since 10:00 too: 3h 30m and 1h 30m.
        ->assertSee('id="rfq-time-spent-total">5h', false)
        ->assertSee('In progress');
});

it('reports every role\'s time per RFQ and on average, for Admin and Senior Operations alone', function () {
    test()->travelTo(colombo('2026-10-05 09:00'));
    $one = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7001']), [1 => userWithRole('Sourcing')]);
    $two = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7002']), [1 => userWithRole('Sourcing')]);
    Rfq::factory()->create(['rfq_number' => 'RFQ7003']);

    test()->travelTo(colombo('2026-10-05 10:00'));
    $one->refresh()->completeSourcingPart(1);
    startDataEntryOn($one, 1);
    test()->travelTo(colombo('2026-10-05 12:00'));
    $two->refresh()->completeSourcingPart(1);
    startDataEntryOn($two, 1);
    test()->travelTo(colombo('2026-10-05 12:30'));

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee('<title>Time Spent', false)
        ->assertSee('RFQ7001')
        ->assertSee('RFQ7002')
        // Not started by any tracked role yet.
        ->assertDontSee('RFQ7003')
        ->getContent();

    // Sourcing: 1h and 3h — 2h on average across the two.
    expect($html)->toMatch('/Sourcing · average per RFQ<\/div>\s*<div class="stat-value">2h<\/div>/')
        // Data Entry has had both since they were completed: 2h 30m and 30m.
        ->toMatch('/Data Entry · average per RFQ<\/div>\s*<div class="stat-value">1h 30m<\/div>/');

    test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.time-spent', ['search' => 'RFQ7002']))->assertOk()
        ->assertSee('RFQ7002')
        ->assertDontSee('RFQ7001');

    foreach (['Sourcing', 'Data Entry', 'General Manager', 'Business Development'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.reports.time-spent'))->assertForbidden();
        test()->actingAs(userWithRole($role))->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee(route('admin.reports.time-spent'));
    }

    foreach (['Admin', 'Senior Operations'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.reports.time-spent'));
        test()->actingAs(userWithRole($role))->get(route('admin.reports.time-spent'))->assertOk()
            ->assertSee('RFQ7001');
    }
});

// ---- Elapsed time and short stretches -------------------------------------------

it('still shows work done outside working hours, as elapsed time', function () {
    // Sunday, in the small hours.
    test()->travelTo(colombo('2026-10-04 02:20'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9001']), [1 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-04 02:36:09'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);
    test()->travelTo(colombo('2026-10-04 02:36:28'));
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));

    $time = $rfq->refresh()->timeSpent();

    expect($time['roles']['Sourcing'])->toMatchArray(['minutes' => 0, 'seconds' => 0, 'rounds' => 1])
        ->and($time['roles']['Sourcing']['elapsed'])->toBe(16 * 60 + 9)
        ->and($time['roles']['Data Entry']['elapsed'])->toBe(19);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee('RFQ9001')
        ->assertSee('16m elapsed')
        ->assertSee('19s elapsed');

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('· 16m elapsed', false);
});

it('shows a stretch under a minute in seconds, not as nothing', function () {
    test()->travelTo(colombo('2026-10-05 09:00:00'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9002']), [1 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-05 09:00:24'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);

    expect($rfq->refresh()->timeSpent(colombo('2026-10-05 09:00:24'))['roles']['Sourcing'])->toMatchArray(['minutes' => 0, 'seconds' => 24]);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee('24s');
});

it('reads durations the way the pages show them', function (string $method, int $seconds, string $label) {
    expect(Setting::$method($seconds))->toBe($label);
})->with([
    ['durationLabel', 0, '0m'],
    ['durationLabel', 24, '24s'],
    ['durationLabel', 12 * 60 + 30, '12m'],
    ['durationLabel', 7 * 3600 + 30 * 60, '7h 30m'],
    ['elapsedLabel', 19, '19s'],
    ['elapsedLabel', 3 * 3600 + 5 * 60, '3h 5m'],
    ['elapsedLabel', 2 * 86400 + 17 * 3600 + 40 * 60, '2d 17h'],
    ['elapsedLabel', 86400, '1d'],
]);

// ---- Work done out of hours ---------------------------------------------------------

it('flags a step its role finished outside working hours, with how much fell outside them', function () {
    test()->travelTo(colombo('2026-10-04 02:20'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9101']), [1 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-04 02:36:09'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);

    $step = $rfq->refresh()->steps->firstWhere('step', 'sourcing');

    expect($step->ended_by_role)->toBeTrue()
        ->and($step->isOutOfHoursWork())->toBeTrue()
        ->and($step->outOfHoursSeconds())->toBe(16 * 60 + 9)
        ->and($rfq->timeSpent()['roles']['Sourcing'])->toMatchArray(['out_of_hours' => 16 * 60 + 9, 'out_of_hours_count' => 1]);
});

it('doesn\'t flag a part that only waited overnight, finished within working hours', function () {
    // Friday 16:00 to Monday 09:00 — over the weekend, done on Monday morning.
    test()->travelTo(colombo('2026-10-09 16:00'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-12 09:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);

    expect($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray(['minutes' => 90, 'out_of_hours' => 0, 'out_of_hours_count' => 0]);
});

it('counts the whole stretch outside the hours of one finished out of hours', function () {
    // Friday 16:00 to Saturday 10:00: 1h of working time, 17h outside it.
    test()->travelTo(colombo('2026-10-09 16:00'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-10 10:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);

    expect($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray(['minutes' => 60, 'out_of_hours' => 17 * 3600, 'out_of_hours_count' => 1]);
});

it('flags returns done out of hours too, by whoever sent it back', function () {
    test()->travelTo(colombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-05 10:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1);

    // Data Entry sends it back at 20:00.
    test()->travelTo(colombo('2026-10-05 20:00'));
    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', userWithRole('Data Entry'));

    // 10:00–20:00 with them: 6h working, 4h outside — lunch, and 17:00–20:00.
    expect($rfq->refresh()->timeSpent()['roles']['Data Entry'])->toMatchArray(['out_of_hours_count' => 1, 'out_of_hours' => 4 * 3600])
        ->and($rfq->timeSpent()['roles']['Sourcing']['out_of_hours_count'])->toBe(0);
});

it('doesn\'t flag a step ended at night by something else — the part freed, or the RFQ held', function () {
    // Taken off its member by Senior Operations' Get Details Again, at 21:00.
    test()->travelTo(colombo('2026-10-05 09:00'));
    $freed = Rfq::factory()->create();
    $freed->planSplit(2);
    $freed->assignSourcingParts([1 => userWithRole('Sourcing')->id]);
    test()->travelTo(colombo('2026-10-05 21:00'));
    test()->actingAs(userWithRole('Senior Operations'))
        ->patch(route('admin.rfqs.request-details', $freed), ['reason' => 'Need the site address'])
        ->assertSessionHasNoErrors();

    $step = $freed->refresh()->steps->sole();
    expect($step->ended_at)->not->toBeNull()
        ->and($step->ended_by_role)->toBeFalse()
        ->and($step->isOutOfHoursWork())->toBeFalse();

    // GM Assistant's step paused when the General Manager holds the RFQ at 22:00.
    test()->travelTo(colombo('2026-10-05 09:00'));
    $held = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    $head = userWithRole('Head of Business Development');
    foreach ([1, 2] as $part) {
        $held->refresh()->completeSourcingPart($part);
        startDataEntryOn($held, $part);
        $held->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
        $held->refresh()->finalizePart($part);
        $held->refresh()->approveSeniorOpsPart($part, userWithRole('Senior Operations'));
        $held->refresh()->approveHeadOfBdPart($part, $head);
    }
    $held->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'));

    test()->travelTo(colombo('2026-10-05 22:00'));
    $held->refresh()->rejectPartToStage(1, 'head_of_bd_review', 'Check P1', userWithRole('General Manager'), 'gm_review');

    $paused = $held->refresh()->steps->where('step', 'gm_assistant')->firstWhere('part_number', 2);
    expect($paused->ended_at)->not->toBeNull()
        ->and($paused->ended_by_role)->toBeFalse()
        ->and($held->timeSpent()['roles']['GM Assistant']['out_of_hours_count'])->toBe(0);
});

it('highlights out-of-hours work on the report and the RFQ\'s page, and lists it on its own', function () {
    test()->travelTo(colombo('2026-10-04 02:20'));
    $night = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9201']), [1 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-04 02:36'));
    $night->refresh()->completeSourcingPart(1);
    startDataEntryOn($night, 1);

    test()->travelTo(colombo('2026-10-05 09:00'));
    $day = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9202']), [1 => userWithRole('Sourcing')]);
    test()->travelTo(colombo('2026-10-05 10:00'));
    $day->refresh()->completeSourcingPart(1);
    startDataEntryOn($day, 1);

    $admin = userWithRole('Admin');

    $html = test()->actingAs($admin)->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee('Out of working hours')
        ->assertSee('1 step')
        ->getContent();

    $nightRow = Str::betweenFirst($html, '>RFQ9201</a>', '</tr>');
    $dayRow = Str::betweenFirst($html, '>RFQ9202</a>', '</tr>');

    expect($nightRow)->toContain('time-out-of-hours')->toContain('16m out of hours')
        ->and($dayRow)->not->toContain('time-out-of-hours');

    test()->actingAs($admin)->get(route('admin.reports.time-spent', ['tab' => 'out-of-hours']))->assertOk()
        ->assertSee('RFQ9201')
        ->assertDontSee('RFQ9202');

    test()->actingAs($admin)->get(route('admin.rfqs.show', $night))->assertOk()
        ->assertSee('16m out of working hours');
    test()->actingAs($admin)->get(route('admin.rfqs.show', $day))->assertOk()
        ->assertDontSee('out of working hours');
});

// ---- Tabs and the deadline ------------------------------------------------------------

/**
 * Four RFQs on Monday 2026-10-05, seen at 15:30: RFQ9301 Urgent and still with
 * Sourcing since 09:00 (overdue), RFQ9302 High and still with them since 14:00
 * (on track), RFQ9303 Urgent, finished at 14:30 (late — 4h 30m against 4h),
 * and RFQ9304, finished in an hour and closed.
 *
 * @return array<string, Rfq>
 */
function deadlineSpread(): array
{
    $sourcing = userWithRole('Sourcing');

    test()->travelTo(colombo('2026-10-05 09:00'));
    $overdue = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9301', 'priority_level' => 'Urgent']), [1 => $sourcing]);
    $late = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9303', 'priority_level' => 'Urgent']), [1 => $sourcing]);
    $closed = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9304', 'priority_level' => 'Medium']), [1 => $sourcing]);

    test()->travelTo(colombo('2026-10-05 10:00'));
    $closed->refresh()->completeSourcingPart(1);
    startDataEntryOn($closed, 1);
    $closed->refresh()->update(['status' => 'Completed']);
    $closed->refresh()->syncSteps();

    test()->travelTo(colombo('2026-10-05 14:00'));
    $onTrack = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9302', 'priority_level' => 'High']), [1 => $sourcing]);

    test()->travelTo(colombo('2026-10-05 14:30'));
    $late->refresh()->completeSourcingPart(1);
    startDataEntryOn($late, 1);

    test()->travelTo(colombo('2026-10-05 15:30'));

    return ['overdue' => $overdue, 'onTrack' => $onTrack, 'late' => $late, 'closed' => $closed];
}

it('tells how each RFQ stands against Sourcing\'s deadline', function () {
    ['overdue' => $overdue, 'onTrack' => $onTrack, 'late' => $late, 'closed' => $closed] = deadlineSpread();

    // 09:00–15:30 less lunch is 5h 30m, against an Urgent 4h.
    expect($overdue->refresh()->sourcingDeadline())->toMatchArray(['remaining' => -90 * 60, 'late_rounds' => 0, 'exceeded' => true])
        ->and($onTrack->refresh()->sourcingDeadline())->toMatchArray(['remaining' => 8 * 3600 - 90 * 60, 'exceeded' => false])
        // 09:00–14:30 less lunch is 4h 30m: 30m late.
        ->and($late->refresh()->sourcingDeadline())->toMatchArray(['remaining' => null, 'late_rounds' => 1, 'late_by' => 30 * 60, 'exceeded' => true])
        ->and($closed->refresh()->sourcingDeadline())->toMatchArray(['remaining' => null, 'late_rounds' => 0, 'exceeded' => false])
        ->and(Rfq::factory()->create()->sourcingDeadline())->toBeNull();
});

it('splits the report into tabs, each with its count, opening on Pending', function () {
    deadlineSpread();
    $admin = userWithRole('Admin');
    $listed = fn (array $query) => collect(['RFQ9301', 'RFQ9302', 'RFQ9303', 'RFQ9304'])
        ->filter(fn (string $number) => str_contains(test()->actingAs($admin)->get(route('admin.reports.time-spent', $query))->getContent(), '>'.$number.'</a>'))
        ->values()->all();

    $html = test()->actingAs($admin)->get(route('admin.reports.time-spent'))->assertOk()->getContent();
    expect($html)->toMatch('/class="nav-link active"[^>]*>\s*Pending\s*<span class="badge badge-soft-secondary">3<\/span>/')
        ->toMatch('/Deadline exceeded\s*<span class="badge badge-soft-danger">2<\/span>/')
        ->toMatch('/Closed\s*<span class="badge badge-soft-secondary">1<\/span>/')
        ->toMatch('/All\s*<span class="badge badge-soft-secondary">4<\/span>/');

    expect($listed([]))->toBe(['RFQ9301', 'RFQ9302', 'RFQ9303'])
        ->and($listed(['tab' => 'exceeded']))->toBe(['RFQ9301', 'RFQ9303'])
        ->and($listed(['tab' => 'closed']))->toBe(['RFQ9304'])
        ->and($listed(['tab' => 'all']))->toBe(['RFQ9301', 'RFQ9302', 'RFQ9303', 'RFQ9304'])
        ->and($listed(['tab' => 'nowhere']))->toBe(['RFQ9301', 'RFQ9302', 'RFQ9303'])
        // The search narrows a tab, and stays on it.
        ->and($listed(['tab' => 'exceeded', 'search' => 'RFQ9303']))->toBe(['RFQ9303']);
});

it('shows each RFQ\'s deadline in its row', function () {
    deadlineSpread();

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.time-spent', ['tab' => 'all']))->assertOk()->getContent();
    $row = fn (string $number) => Str::betweenFirst($html, '>'.$number.'</a>', '</tr>');

    expect($row('RFQ9301'))->toContain('badge-soft-danger')->toContain('Overdue by 1h 30m')
        ->and($row('RFQ9302'))->toContain('6h 30m left')
        ->and($row('RFQ9303'))->toContain('Finished 30m late')
        ->and($row('RFQ9304'))->toContain('On time');
});

it('opens the report to Senior Operations, without the Admin-only link to the working hours', function () {
    deadlineSpread();

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.reports.time-spent', ['tab' => 'exceeded']))->assertOk()
        ->assertSee('RFQ9301')
        ->assertDontSee(route('admin.settings.edit', ['tab' => 'working-hours']));

    test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee(route('admin.settings.edit', ['tab' => 'working-hours']));
});
