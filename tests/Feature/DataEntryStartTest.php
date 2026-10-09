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
 * A moment in Colombo — the working hours' own time zone; 2026-10-05 is a
 * Monday: 08:30–17:00, lunch 12:30–13:30.
 */
function startClock(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * RFQ20001, its one part assigned to Data Entry by Sourcing at 09:00 Monday.
 */
function handedToDataEntry(): Rfq
{
    test()->travelTo(startClock('2026-10-05 08:30'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ20001']), [1 => userWithRole('Sourcing')]);

    test()->travelTo(startClock('2026-10-05 09:00'));
    $rfq->refresh()->completeSourcingPart(1);

    return $rfq->refresh();
}

it('gives Data Entry a Start on a part, not Send to Finalize, until they\'ve started', function () {
    $rfq = handedToDataEntry();
    test()->travelTo(startClock('2026-10-05 10:00'));

    $html = test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    $row = Str::betweenFirst($html, '<td class="text-nowrap">RFQ20001</td>', '</tr>');

    expect($row)->toContain('action="'.route('admin.rfqs.start-data-entry', $rfq).'"')
        ->toContain('data-start-hint="Start — your time on it counts from now."')
        ->toMatch('/class="btn btn-sm btn-primary js-working-hours-only"\s*>/')
        ->not->toContain('data-kind="data_entry"')
        // Return to Sourcing doesn't wait for it.
        ->toContain('data-kind="return"')
        ->and($html)->toContain('id="off-hours"');
});

it('counts Data Entry\'s time from their Start, not from when Sourcing handed it over', function () {
    $rfq = handedToDataEntry();
    $morgan = userWithRole('Data Entry');

    // Nothing timed while it waits to be started.
    test()->travelTo(startClock('2026-10-05 10:00'));
    expect(RfqStep::query()->where('step', 'data_entry')->count())->toBe(0);

    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Started RFQ20001 — your time on it is counting.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot)
        ->data_entry_started_by->toBe($morgan->id)
        ->and($rfq->assigneeForPart(1)->pivot->data_entry_started_at->eq(now()))->toBeTrue();

    test()->travelTo(startClock('2026-10-05 11:00'));
    test()->actingAs($morgan)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'comment' => 'Prices entered'])
        ->assertSessionHasNoErrors();

    // 10:00–11:00, not 09:00–11:00 — and Morgan's.
    $stretch = RfqStep::query()->where('step', 'data_entry')->sole();
    expect($rfq->refresh()->timeSpent()['roles']['Data Entry'])->toMatchArray(['seconds' => 3600])
        ->and($stretch->worked_by)->toBe($morgan->id);

    test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk();
});

it('keeps Start off at lunch, after hours and on a day off — saying why, on the page and on the server', function (string $at, string $why) {
    $rfq = handedToDataEntry();
    test()->travelTo(startClock($at));
    $dataEntry = userWithRole('Data Entry');

    $row = Str::betweenFirst(
        test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent(),
        '<td class="text-nowrap">RFQ20001</td>', '</tr>'
    );
    expect($row)->toMatch('/js-working-hours-only" disabled/')
        ->toContain('start-hint js-start-hint is-off')
        ->toContain('data-start-hint="'.e($why).'"');

    test()->actingAs($dataEntry)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])
        ->assertSessionHas('error', "Start is off right now — {$why}");

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_started_at)->toBeNull();
})->with([
    'before hours' => ['2026-10-06 07:45', "Working hours haven't started yet — they start at 08:30."],
    'lunch' => ['2026-10-05 13:00', "It's lunch time (12:30–13:30). Start is back at 13:30."],
    'evening' => ['2026-10-05 18:30', 'Working hours are over for today — they ended at 17:00. Start is back tomorrow at 08:30.'],
    'Friday evening' => ['2026-10-09 17:30', 'Working hours are over for today — they ended at 17:00. Start is back on Monday at 08:30.'],
    'Saturday' => ['2026-10-10 10:00', 'Saturday is a day off. Start is back on Monday at 08:30.'],
]);

it('sends the off-hours windows ahead, each with why, for the page to follow without a reload', function () {
    Setting::putWorkingHours(array_merge(Setting::workingHours(), [
        'monday' => ['working' => true, 'start' => '09:00', 'end' => '16:00', 'lunch_start' => null, 'lunch_end' => null],
    ]));

    $windows = collect(Setting::offHoursWindows(startClock('2026-10-04 15:00'), 2))
        ->map(fn (array $window) => [
            CarbonImmutable::createFromTimestamp($window[0], Setting::DEFAULT_TIMEZONE)->format('D H:i'),
            CarbonImmutable::createFromTimestamp($window[1], Setting::DEFAULT_TIMEZONE)->format('D H:i'),
            $window[2],
        ])->all();

    // Sunday's off all day; Monday — no lunch now — only before and after.
    expect($windows)->toBe([
        ['Sun 00:00', 'Mon 00:00', 'Sunday is a day off. Start is back tomorrow at 09:00.'],
        ['Mon 00:00', 'Mon 09:00', "Working hours haven't started yet — they start at 09:00."],
        ['Mon 16:00', 'Tue 00:00', 'Working hours are over for today — they ended at 16:00. Start is back tomorrow at 08:30.'],
    ])
        ->and(Setting::offHoursReason(startClock('2026-10-05 12:45')))->toBeNull()
        ->and(Setting::offHoursReason(startClock('2026-10-05 16:00')))->toStartWith('Working hours are over for today');
});

it('won\'t send a part to finalize before Data Entry has started on it', function () {
    $rfq = handedToDataEntry();
    test()->travelTo(startClock('2026-10-05 10:00'));
    $dataEntry = userWithRole('Data Entry');

    test()->actingAs($dataEntry)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'comment' => 'Done'])
        ->assertSessionHas('error', 'Start on this part first — Send to Finalize comes after.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_completed_at)->toBeNull();

    // Returning it to Sourcing doesn't need a Start.
    test()->actingAs($dataEntry)->patch(route('admin.rfqs.return-sourcing', $rfq), ['part' => 1, 'reason' => 'Wrong supplier'])
        ->assertSessionHasNoErrors();
});

it('starts a part only once, only while it\'s with Data Entry, and only for Data Entry or Admin', function () {
    $rfq = handedToDataEntry();
    test()->travelTo(startClock('2026-10-05 10:00'));

    test()->actingAs(userWithRole('Sourcing'))->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])->assertForbidden();

    $morgan = userWithRole('Data Entry');
    test()->actingAs(userWithRole('Admin'))->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1, 'acting_user_id' => $morgan->id])
        ->assertSessionHasNoErrors();
    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_started_by)->toBe($morgan->id);

    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])->assertStatus(422);

    // Not one still with Sourcing.
    $withSourcing = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $withSourcing), ['part' => 1])->assertStatus(422);
});

// ---- One part at a time ---------------------------------------------------------

/**
 * $rfqNumber, its one part handed to Data Entry by Sourcing at 09:00 Monday.
 */
function waitingOnDataEntry(string $rfqNumber): Rfq
{
    test()->travelTo(startClock('2026-10-05 08:30'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $rfqNumber]), [1 => userWithRole('Sourcing')]);

    test()->travelTo(startClock('2026-10-05 09:00'));
    $rfq->refresh()->completeSourcingPart(1);

    return $rfq->refresh();
}

it('lets Data Entry start one part at a time — no other till that one\'s sent to finalize', function () {
    $first = waitingOnDataEntry('RFQ20001');
    $second = waitingOnDataEntry('RFQ20002');
    $morgan = userWithRole('Data Entry');

    test()->travelTo(startClock('2026-10-05 10:00'));
    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $first), ['part' => 1])->assertSessionHasNoErrors();

    // Off on the other, saying which one's running — whatever the hour.
    $busy = 'You&#039;re already on RFQ20001 — send it to finalize before starting another.';
    $row = Str::betweenFirst(
        test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent(),
        '<td class="text-nowrap">RFQ20002</td>', '</tr>'
    );
    expect($row)->toMatch('/js-working-hours-only" disabled/')
        ->toContain('start-hint js-start-hint is-off')
        ->toContain('data-busy-hint="'.$busy.'"')
        ->toContain('data-start-hint="'.$busy.'"');

    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $second), ['part' => 1])
        ->assertSessionHas('error', "You're already on RFQ20001 — send it to finalize before starting another.");
    expect($second->refresh()->assigneeForPart(1)->pivot->data_entry_started_at)->toBeNull();

    // Sent to finalize, it's free to start the next.
    test()->travelTo(startClock('2026-10-05 10:30'));
    test()->actingAs($morgan)->patch(route('admin.rfqs.complete-data-entry', $first), ['part' => 1, 'comment' => 'Prices entered'])
        ->assertSessionHasNoErrors();

    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $second), ['part' => 1])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Started RFQ20002 — your time on it is counting.');
});

it('holds each Data Entry person to their own one part — Admin starting for them too', function () {
    $first = waitingOnDataEntry('RFQ20001');
    $second = waitingOnDataEntry('RFQ20002');
    $morgan = userWithRole('Data Entry');
    $morgan->update(['name' => 'Morgan']);

    test()->travelTo(startClock('2026-10-05 10:00'));
    startDataEntryOn($first, 1, $morgan);

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.rfqs.start-data-entry', $second), ['part' => 1, 'acting_user_id' => $morgan->id])
        ->assertSessionHas('error', 'Morgan is already on RFQ20001 — send it to finalize before starting another.');

    // Someone else in Data Entry isn't held up by Morgan's.
    $casey = userWithRole('Data Entry');
    test()->actingAs($casey)->patch(route('admin.rfqs.start-data-entry', $second), ['part' => 1])->assertSessionHasNoErrors();
    expect($second->refresh()->assigneeForPart(1)->pivot->data_entry_started_by)->toBe($casey->id);
});

it('frees Data Entry to start another once the running part leaves them some other way', function (Closure $leave) {
    $first = waitingOnDataEntry('RFQ20001');
    $second = waitingOnDataEntry('RFQ20002');
    $morgan = userWithRole('Data Entry');

    test()->travelTo(startClock('2026-10-05 10:00'));
    startDataEntryOn($first, 1, $morgan);
    $leave($first->refresh(), $morgan);

    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $second), ['part' => 1])->assertSessionHasNoErrors();
    expect($second->refresh()->assigneeForPart(1)->pivot->data_entry_started_by)->toBe($morgan->id);
})->with([
    'sent back to Sourcing' => [fn (Rfq $rfq, $morgan) => $rfq->returnSourcingPart(1, 'Wrong supplier', $morgan)],
    'put on hold' => [fn (Rfq $rfq) => $rfq->changePartStatus(1, Rfq::ON_HOLD, userWithRole('Senior Operations'), 'Client paused it')],
]);
