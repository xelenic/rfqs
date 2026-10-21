<?php

use App\Models\Rfq;
use App\Models\User;
use App\Notifications\DataEntryAssigned;
use App\Notifications\DataEntryIdle;
use App\Notifications\LineItemsDiscrepancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * RFQ8501 ("Valves"), Riley Chen in Sourcing on its one part, Morgan Ito in
 * Data Entry — and Olivia Park (Senior Operations) and Quinn Alder (General
 * Manager) to be told of what goes wrong.
 *
 * @return array{rfq: Rfq, riley: User, morgan: User, ops: User, gm: User}
 */
function timelineCast(): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);

    return [
        'rfq' => splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8501', 'subject' => 'Valves']), [1 => $riley]),
        'riley' => $riley,
        'morgan' => tap(userWithRole('Data Entry'))->update(['name' => 'Morgan Ito']),
        'ops' => tap(userWithRole('Senior Operations'))->update(['name' => 'Olivia Park']),
        'gm' => tap(userWithRole('General Manager'))->update(['name' => 'Quinn Alder']),
    ];
}

/**
 * The RFQ page's Activity Timeline, as $viewer sees it.
 */
function timelineOf(Rfq $rfq, User $viewer): string
{
    $html = test()->actingAs($viewer)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    return Str::betweenFirst($html, 'Activity Timeline', 'card-footer');
}

it('puts the line items alert on the RFQ\'s timeline — who it went to, and what it said', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = timelineCast();
    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Prices in', 'line_items' => 24]);
    startDataEntryOn($rfq, 1, $morgan);
    test()->actingAs($morgan)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'entries' => 22, 'comment' => 'Entered']);

    expect(timelineOf($rfq, userWithRole('Admin')))
        ->toContain('Line item count mismatch — alert sent')
        ->toContain('Sent to')
        ->toContain('Olivia Park and Quinn Alder')
        ->toContain('RFQ8501 — Valves: Morgan Ito added 22 line items, but Sourcing asked for 24 (2 fewer than requested).');
});

it('words the line items alert plainly — one sent before the wording changed too, and one line item', function () {
    ['rfq' => $rfq, 'morgan' => $morgan, 'ops' => $ops] = timelineCast();

    // As it was worded when it was sent.
    $ops->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => LineItemsDiscrepancy::class,
        'data' => ['rfq_id' => $rfq->id, 'part' => 1, 'added' => 3, 'to_add' => 1, 'discrepancy' => 2, 'added_by' => $morgan->id,
            'message' => 'RFQ8501: Morgan Ito added 3 line items, 2 more than the 1 to add — Valves', 'icon' => 'bi-exclamation-diamond'],
    ]);

    expect(timelineOf($rfq, userWithRole('Admin')))
        ->toContain('RFQ8501 — Valves: Morgan Ito added 3 line items, but Sourcing asked for 1 (2 more than requested).')
        ->not->toContain('than the 1 to add');

    expect(LineItemsDiscrepancy::messageFor('RFQ8501', 'Valves', 'Morgan Ito', 1, 3))
        ->toBe('RFQ8501 — Valves: Morgan Ito added 1 line item, but Sourcing asked for 3 (2 fewer than requested).');
});

it('puts Data Entry\'s pause alert on it, with the reason', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = timelineCast();
    $rfq->completeSourcingPart(1);
    startDataEntryOn($rfq, 1, $morgan);

    test()->actingAs($morgan)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route('admin.rfqs.pause-data-entry', $rfq), ['part' => 1, 'pause_reason' => 'Waiting for the price list', 'pause_reference' => 'PO-77'])
        ->assertSessionHas('status');

    expect(timelineOf($rfq, userWithRole('Admin')))
        ->toContain('Data Entry paused — alert sent')
        ->toContain('Olivia Park and Quinn Alder')
        ->toContain('Morgan Ito paused RFQ8501: Waiting for the price list (Ref PO-77) — Valves');
});

it('puts the "assigned to you" alert on it, saying whom it went to', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = timelineCast();

    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Prices in', 'line_items' => 12, 'data_entry_user_id' => $morgan->id])
        ->assertSessionHas('status');

    expect(timelineOf($rfq, userWithRole('Admin')))
        ->toContain('Assigned in Data Entry — they were told')
        ->toContain('Riley Chen assigned RFQ8501 to Morgan Ito — Valves')
        ->not->toContain('to you');
});

it('makes one entry of an alert however many it went to, in order with the rest', function () {
    ['rfq' => $rfq, 'morgan' => $morgan, 'ops' => $ops, 'gm' => $gm] = timelineCast();
    $rfq->completeSourcingPart(1, null, null, 24);
    startDataEntryOn($rfq, 1, $morgan);
    $rfq->refresh()->completeDataEntryPart(1, $morgan, 'Entered', 22);
    Notification::send([$ops, $gm], new LineItemsDiscrepancy($rfq->refresh(), 1, 22, 24, $morgan));

    $alerts = $rfq->refresh()->sentAlerts();
    expect($alerts)->toHaveCount(1)
        ->and($alerts->sole()['recipients']->pluck('name')->sort()->values()->all())->toBe(['Olivia Park', 'Quinn Alder']);

    $types = collect($rfq->activityTimeline())->pluck('type')->all();
    expect(array_search('notification', $types, true))->toBeGreaterThan(array_search('data_entry_part_completed', $types, true));
});

it('leaves out alerts about other RFQs, and ones about no RFQ at all', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan, 'ops' => $ops] = timelineCast();
    $other = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8502']), [1 => $riley]);
    $morgan->notify(new DataEntryAssigned($other, 1, $riley));
    $ops->notify(new DataEntryIdle($morgan, 45, 2));

    expect($rfq->sentAlerts())->toBeEmpty()
        ->and($other->sentAlerts())->toHaveCount(1)
        ->and(timelineOf($rfq, userWithRole('Admin')))->not->toContain('alert sent')->not->toContain('they were told');
});

it('blurs who and what for Sourcing, who never learns who in Data Entry it was', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = timelineCast();
    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Prices in', 'line_items' => 12, 'data_entry_user_id' => $morgan->id]);

    $timeline = timelineOf($rfq, $riley);

    expect($timeline)->toContain('Assigned in Data Entry — they were told')
        ->and(Str::betweenFirst($timeline, 'Assigned in Data Entry — they were told', '</div>'."\n".'</div>'))
        ->toContain('rfq-timeline-meta rfq-blurred')
        ->toContain('rfq-timeline-detail text-muted-soft rfq-blurred');
});

it('assigns a part to Data Entry, and tells them, once — however often it\'s sent', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = timelineCast();
    $assign = fn () => test()->actingAs($riley)->from(route('admin.rfqs.show', $rfq))
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Please add this link', 'line_items' => 3, 'data_entry_user_id' => $morgan->id]);

    $assign()->assertSessionHas('status', 'Assigned to Morgan Ito in Data Entry.');
    // A double click, or the browser trying again.
    $assign()->assertSessionHas('status', 'This part is already assigned to Data Entry.');

    expect($morgan->notifications()->where('type', DataEntryAssigned::class)->count())->toBe(1)
        ->and($rfq->comments()->where('action', 'sourcing_completed')->count())->toBe(1)
        ->and($morgan->receivedMessages()->count())->toBe(1)
        ->and(substr_count(timelineOf($rfq, userWithRole('Admin')), 'Assigned in Data Entry — they were told'))->toBe(1);
});

it('sends a part back to Sourcing once, however often it\'s sent', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = timelineCast();
    $rfq->completeSourcingPart(1, 'Prices in', $morgan, 3);
    $return = fn () => test()->actingAs($morgan)->from(route('admin.rfqs.show', $rfq))
        ->patch(route('admin.rfqs.return-sourcing', $rfq), ['part' => 1, 'entries' => 2, 'reason' => 'The link is broken']);

    $return()->assertSessionHas('status', 'Sent Riley Chen\'s part back to Sourcing.');
    $return()->assertSessionHas('status', 'Riley Chen\'s part is already back with Sourcing.');

    expect($rfq->comments()->where('action', 'returned_to_sourcing')->count())->toBe(1)
        ->and($riley->receivedMessages()->count())->toBe(1);
});

it('shows the same alert sent again moments later as the one send — the duplicates already sent included', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = timelineCast();
    $rfq->completeSourcingPart(1, 'Prices in', $morgan, 3);

    // As a double submit used to leave it: told twice, seconds apart.
    test()->travelTo(now()->startOfMinute());
    $morgan->notify(new DataEntryAssigned($rfq->refresh(), 1, $riley));
    test()->travel(4)->seconds();
    $morgan->notify(new DataEntryAssigned($rfq, 1, $riley));

    expect(substr_count(timelineOf($rfq, userWithRole('Admin')), 'Assigned in Data Entry — they were told'))->toBe(1);

    // Told again ten minutes later — say, after it was sent back and assigned again — is a send of its own.
    test()->travel(10)->minutes();
    $morgan->notify(new DataEntryAssigned($rfq, 1, $riley));

    expect(substr_count(timelineOf($rfq, userWithRole('Admin')), 'Assigned in Data Entry — they were told'))->toBe(2);
});
