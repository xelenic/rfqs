<?php

use App\Models\Rfq;
use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Travels to $time ("H:i") on Monday 5 October in Colombo — 08:30–17:00,
 * lunch 12:30–13:30.
 */
function pauseClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ $number, High priority — an hour for Data Entry — its one part handed
 * to Data Entry at 08:45.
 */
function handedOver(string $number): Rfq
{
    pauseClock('08:40');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'priority_level' => 'High']), [1 => userWithRole('Sourcing')]);
    pauseClock('08:45');
    $rfq->refresh()->completeSourcingPart(1);

    return $rfq->refresh();
}

/**
 * $user does $action on part 1 of $rfq — a pause saying why, and the
 * document it's waiting on, unless $payload says otherwise.
 */
function dataEntryDoes(User $user, string $action, Rfq $rfq, array $payload = []): TestResponse
{
    $pauseDetails = $action === 'pause-data-entry' ? ['pause_reason' => 'Waiting for the supplier quote', 'pause_reference' => 'PO-4471'] : [];

    return test()->actingAs($user)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route("admin.rfqs.{$action}", $rfq), ['part' => 1] + $payload + $pauseDetails);
}

function readyRow(User $viewer, string $number): string
{
    $html = test()->actingAs($viewer)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    return Str::betweenFirst($html, '<td class="text-nowrap">'.$number.'</td>', '</tr>');
}

it('lets Data Entry pause the part they\'re on — its countdown stops where it is', function () {
    $morgan = userWithRole('Data Entry');
    $rfq = handedOver('RFQ5001');

    pauseClock('09:10');
    dataEntryDoes($morgan, 'start-data-entry', $rfq);

    // Running: Pause beside Send to Finalize.
    pauseClock('09:30');
    expect(readyRow($morgan, 'RFQ5001'))
        ->toContain('action="'.route('admin.rfqs.pause-data-entry', $rfq).'"')
        ->toContain('Send to Finalize');

    dataEntryDoes($morgan, 'pause-data-entry', $rfq)
        ->assertSessionHas('status', "Paused RFQ5001 — its countdown has stopped. You can start another, and resume this one once you've nothing running.");

    // An hour later, still the 40 minutes it had.
    pauseClock('10:30');
    $rfq->refresh();

    expect($rfq->assigneeForPart(1)->pivot->isDataEntryPaused())->toBeTrue()
        ->and($rfq->dataEntryCountdown(1))->toBeNull()
        ->and($rfq->pausedDataEntryCountdown(1))->toBe(['target' => 3600, 'remaining' => 2400])
        ->and(RfqStep::runningDataEntryOf($morgan))->toBeNull();

    expect(readyRow($morgan, 'RFQ5001'))
        ->toContain('Paused 9:30 AM')
        ->toContain('40m')
        ->toContain('action="'.route('admin.rfqs.resume-data-entry', $rfq).'"')
        ->not->toContain('Send to Finalize')
        ->not->toContain('data-countdown');
});

it('frees them to start another, and to resume the paused one once that\'s sent to finalize — carrying on its countdown', function () {
    $morgan = userWithRole('Data Entry');
    $first = handedOver('RFQ5101');
    $second = handedOver('RFQ5102');

    pauseClock('09:10');
    dataEntryDoes($morgan, 'start-data-entry', $first);
    pauseClock('09:30');
    dataEntryDoes($morgan, 'pause-data-entry', $first);

    // Paused, so they can start the next.
    pauseClock('09:31');
    dataEntryDoes($morgan, 'start-data-entry', $second)->assertSessionHas('status', 'Started RFQ5102 — your time on it is counting.');

    // But not resume while that one runs.
    pauseClock('10:00');
    expect(readyRow($morgan, 'RFQ5101'))->toMatch('/btn btn-sm btn-primary" disabled>\s*<i class="bi bi-play-fill"><\/i> Resume/');
    dataEntryDoes($morgan, 'resume-data-entry', $first)
        ->assertSessionHas('error', "You're on RFQ5102 — send it to finalize, or pause it, before resuming this one.");

    // Sent to finalize: now it resumes, the gap not counted.
    dataEntryDoes($morgan, 'complete-data-entry', $second, ['comment' => 'Entered', 'entries' => 3]);
    pauseClock('11:00');
    dataEntryDoes($morgan, 'resume-data-entry', $first)
        ->assertSessionHas('status', 'Resumed RFQ5101 — its countdown carries on from where it stopped.');

    pauseClock('11:10');
    expect($first->refresh()->dataEntryCountdown(1))->toBe(['target' => 3600, 'remaining' => 1800])
        ->and(RfqStep::runningDataEntryOf($morgan)?->rfq_id)->toBe($first->id);

    // Its time on it: 20 minutes before the pause and 10 after — one round.
    $rounds = Rfq::roundsOf(RfqStep::query()->where('rfq_id', $first->id)->where('step', 'data_entry')->oldest('started_at')->get());
    expect($rounds)->toHaveCount(1)
        ->and($rounds->first()->sum(fn (RfqStep $stretch) => $stretch->workingSeconds()))->toBe(30 * 60);
});

it('won\'t send a paused part to finalize — it\'s resumed first', function () {
    $morgan = userWithRole('Data Entry');
    $rfq = handedOver('RFQ5201');
    pauseClock('09:10');
    dataEntryDoes($morgan, 'start-data-entry', $rfq);
    dataEntryDoes($morgan, 'pause-data-entry', $rfq);

    dataEntryDoes($morgan, 'complete-data-entry', $rfq, ['comment' => 'Done', 'entries' => 2])
        ->assertSessionHas('error', "Resume this part first — it's paused.");

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_completed_at)->toBeNull();
});

it('leaves pausing and resuming to whoever started it — or Admin', function () {
    $morgan = userWithRole('Data Entry');
    $sam = userWithRole('Data Entry');
    $rfq = handedOver('RFQ5301');
    pauseClock('09:10');
    dataEntryDoes($morgan, 'start-data-entry', $rfq);

    dataEntryDoes($sam, 'pause-data-entry', $rfq)->assertForbidden();
    dataEntryDoes(userWithRole('Sourcing'), 'pause-data-entry', $rfq)->assertForbidden();
    expect(readyRow($sam, 'RFQ5301'))->not->toContain(route('admin.rfqs.pause-data-entry', $rfq));

    dataEntryDoes(userWithRole('Admin'), 'pause-data-entry', $rfq)->assertSessionHas('status');
    dataEntryDoes($sam, 'resume-data-entry', $rfq)->assertForbidden();
    dataEntryDoes($morgan, 'resume-data-entry', $rfq)->assertSessionHas('status');

    // Nothing to pause or resume that isn't running or paused.
    dataEntryDoes($morgan, 'resume-data-entry', $rfq)->assertSessionHas('error', "This part isn't paused.");
});

it('forgets the pause when the part goes back to Sourcing — its next start is a fresh one', function () {
    $morgan = userWithRole('Data Entry');
    $rfq = handedOver('RFQ5401');
    pauseClock('09:10');
    dataEntryDoes($morgan, 'start-data-entry', $rfq);
    dataEntryDoes($morgan, 'pause-data-entry', $rfq);

    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', $morgan);

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->data_entry_paused_at)->toBeNull()
        ->and($part->hasDataEntryStarted())->toBeFalse();
});

it('shows a paused part as paused on the RFQ\'s progress chart', function () {
    $morgan = userWithRole('Data Entry');
    $rfq = handedOver('RFQ5501');
    pauseClock('09:10');
    dataEntryDoes($morgan, 'start-data-entry', $rfq);
    pauseClock('09:40');
    dataEntryDoes($morgan, 'pause-data-entry', $rfq);

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();
    $tree = json_decode(Str::betweenFirst($html, '<script type="application/json" id="rfq-progress-data">', '</script>'), true);
    $dataEntry = collect(json_decode(json_encode($tree), true))->dot()->filter(fn ($value, $key) => str_ends_with($key, 'role') && $value === 'Data Entry')->keys()->first();
    $node = data_get($tree, Str::beforeLast($dataEntry, '.role'));

    expect($node['meta'])->toBe(['Paused: Waiting for the supplier quote', 'Since Oct 05, 2026 9:40 AM']);
});

it('asks why — and for the document it\'s waiting on, if any — and shows both while it\'s paused', function () {
    $morgan = userWithRole('Data Entry');
    $rfq = handedOver('RFQ5601');
    pauseClock('09:10');
    dataEntryDoes($morgan, 'start-data-entry', $rfq);

    // The popup is on the page, and the button opens it.
    $html = test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();
    expect($html)->toContain('id="pauseDataEntryModal"')
        ->toContain('name="pause_reason"')
        ->toContain('name="pause_reference"')
        ->toContain('data-bs-target="#pauseDataEntryModal"');

    pauseClock('09:30');
    dataEntryDoes($morgan, 'pause-data-entry', $rfq, ['pause_reason' => 'Supplier hasn\'t sent prices', 'pause_reference' => 'QT-2210'])
        ->assertSessionHas('status');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->data_entry_pause_reason)->toBe('Supplier hasn\'t sent prices')
        ->and($part->data_entry_pause_reference)->toBe('QT-2210');

    expect(readyRow($morgan, 'RFQ5601'))
        ->toContain(e('Supplier hasn\'t sent prices'))
        ->toContain('QT-2210');

    // On the RFQ's thread, as Morgan's.
    $comment = $rfq->comments()->where('action', 'data_entry_paused')->sole();
    expect($comment->user_id)->toBe($morgan->id)
        ->and($comment->body)->toBe('Supplier hasn\'t sent prices')
        ->and($comment->actionLabel())->toBe('Paused')
        ->and($comment->actionContext())->toBe('Ref QT-2210');

    // Resumed: the reason's done with — the thread keeps it.
    dataEntryDoes($morgan, 'resume-data-entry', $rfq);
    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->data_entry_pause_reason)->toBeNull()
        ->and($part->data_entry_pause_reference)->toBeNull()
        ->and($rfq->comments()->where('action', 'data_entry_paused')->count())->toBe(1);
});

it('won\'t pause without a reason — the document reference can be left out', function () {
    $morgan = userWithRole('Data Entry');
    $rfq = handedOver('RFQ5701');
    pauseClock('09:10');
    dataEntryDoes($morgan, 'start-data-entry', $rfq);

    dataEntryDoes($morgan, 'pause-data-entry', $rfq, ['pause_reason' => '   ', 'pause_reference' => 'PO-1'])
        ->assertSessionHas('error', "Say why you're pausing it.");
    expect($rfq->refresh()->assigneeForPart(1)->pivot->isDataEntryPaused())->toBeFalse();

    dataEntryDoes($morgan, 'pause-data-entry', $rfq, ['pause_reason' => 'Lunch call with the client', 'pause_reference' => ''])
        ->assertSessionHas('status');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->isDataEntryPaused())->toBeTrue()
        ->and($part->data_entry_pause_reference)->toBeNull()
        ->and($rfq->comments()->where('action', 'data_entry_paused')->sole()->actionContext())->toBeNull();
});
