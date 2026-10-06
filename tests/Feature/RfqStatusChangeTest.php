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

function changeRfqStatus($by, Rfq $rfq, array $payload)
{
    return test()->actingAs($by)->patch(route('admin.rfqs.change-status', $rfq), $payload);
}

it('lets Senior Operations put an RFQ on hold, with why, taking it out of their queue', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ12001', 'subject' => 'Lobby chillers']);

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('Put on hold')
        ->assertSee('Cancel RFQ')
        ->assertSee('id="rfqStatusModal"', false);

    changeRfqStatus($ops, $rfq, ['status' => 'On Hold', 'reason' => 'Client asked to pause until budget is approved'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'RFQ12001 is on hold.');

    expect($rfq->refresh())
        ->status->toBe('On Hold')
        ->status_reason->toBe('Client asked to pause until budget is approved')
        ->status_changed_by->toBe($ops->id)
        ->and($rfq->status_changed_at)->not->toBeNull()
        ->and($rfq->comments()->sole())->toMatchArray(['action' => 'put_on_hold', 'body' => 'Client asked to pause until budget is approved', 'user_id' => $ops->id]);

    // Off their Unassigned queue; on their On Hold page, with why and a Resume.
    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertDontSee('data-rfq-number="RFQ12001"', false);

    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'On Hold']))->assertOk()
        ->assertSee('<title>On Hold RFQs', false)
        ->assertSee('data-rfq-number="RFQ12001"', false)
        ->assertSee('Client asked to pause until budget is approved')
        ->assertSee('Resume');

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('On hold')
        ->assertSee('by '.e($ops->name), false)
        ->assertSee('Resume');
});

it('resumes an RFQ on hold just where it was', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create(['stage' => 'senior_ops_review']);
    changeRfqStatus($ops, $rfq, ['status' => 'On Hold', 'reason' => 'Waiting on the client']);

    changeRfqStatus($ops, $rfq, ['status' => 'Pending'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "{$rfq->rfq_number} is back in progress.");

    expect($rfq->refresh())
        ->status->toBe('Pending')
        ->stage->toBe('senior_ops_review')
        ->status_reason->toBeNull()
        ->and($rfq->comments->last())->toMatchArray(['action' => 'resumed', 'body' => 'Resumed.']);
});

it('cancels an RFQ — open or on hold — and reopens a cancelled one', function () {
    $ops = userWithRole('Senior Operations');
    $open = Rfq::factory()->create(['rfq_number' => 'RFQ12002']);
    $held = Rfq::factory()->create(['rfq_number' => 'RFQ12003']);
    changeRfqStatus($ops, $held, ['status' => 'On Hold', 'reason' => 'Paused']);

    changeRfqStatus($ops, $open, ['status' => 'Cancelled', 'reason' => 'Duplicate of RFQ11990'])->assertSessionHasNoErrors();
    changeRfqStatus($ops, $held, ['status' => 'Cancelled', 'reason' => 'Client went elsewhere'])->assertSessionHasNoErrors();

    expect($open->refresh()->status)->toBe('Cancelled')
        ->and($held->refresh()->status)->toBe('Cancelled');

    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Cancelled']))->assertOk()
        ->assertSee('<title>Cancelled RFQs', false)
        ->assertSee('data-rfq-number="RFQ12002"', false)
        ->assertSee('Duplicate of RFQ11990')
        ->assertSee('Reopen');

    changeRfqStatus($ops, $open, ['status' => 'Pending'])->assertSessionHasNoErrors();

    expect($open->refresh()->status)->toBe('Pending')
        ->and($open->comments->last()->action)->toBe('reopened');
});

it('asks why for a hold or a cancel, and refuses a change that isn\'t one', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create();

    changeRfqStatus($ops, $rfq, ['status' => 'On Hold'])->assertSessionHasErrorsIn('rfq_status', ['reason' => 'Say why.']);
    changeRfqStatus($ops, $rfq, ['status' => 'Cancelled', 'reason' => ''])->assertSessionHasErrorsIn('rfq_status', ['reason']);
    changeRfqStatus($ops, $rfq, ['status' => 'Pending'])->assertSessionHasErrorsIn('rfq_status', ['status']);
    changeRfqStatus($ops, $rfq, ['status' => 'Completed', 'reason' => 'x'])->assertSessionHasErrorsIn('rfq_status', ['status']);

    // A cancelled one can only be reopened, not put on hold.
    changeRfqStatus($ops, $rfq, ['status' => 'Cancelled', 'reason' => 'Duplicate']);
    changeRfqStatus($ops, $rfq, ['status' => 'On Hold', 'reason' => 'x'])->assertSessionHasErrorsIn('rfq_status', ['status']);

    // A closed one is done with.
    $closed = Rfq::factory()->create(['status' => 'Completed', 'stage' => 'closed']);
    changeRfqStatus($ops, $closed, ['status' => 'On Hold', 'reason' => 'x'])->assertStatus(422);

    expect($rfq->refresh()->status)->toBe('Cancelled')
        ->and($closed->refresh()->status)->toBe('Completed');
});

it('lets only Senior Operations and Admin stop or resume an RFQ', function () {
    $rfq = Rfq::factory()->create();

    foreach (['Business Development', 'Sourcing', 'Data Entry', 'General Manager'] as $role) {
        $user = userWithRole($role);
        changeRfqStatus($user, $rfq, ['status' => 'On Hold', 'reason' => 'x'])->assertForbidden();
        test()->actingAs($user)->get(route('admin.rfqs.show', $rfq))->assertOk()->assertDontSee('Put on hold');
    }

    // Admin can, as one of Senior Operations.
    $ops = userWithRole('Senior Operations');
    changeRfqStatus(userWithRole('Admin'), $rfq, ['status' => 'On Hold', 'reason' => 'Paused', 'acting_user_id' => $ops->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->status_changed_by)->toBe($ops->id);
});

it('stops the clock while it\'s on hold, and starts it again on resuming', function () {
    $ops = userWithRole('Senior Operations');
    $colombo = fn (string $time) => CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);

    test()->travelTo($colombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(['priority_level' => 'Urgent']), [1 => userWithRole('Sourcing')]);

    test()->travelTo($colombo('2026-10-05 10:00'));
    changeRfqStatus($ops, $rfq, ['status' => 'On Hold', 'reason' => 'Waiting on drawings']);
    expect(RfqStep::query()->whereNull('ended_at')->count())->toBe(0)
        ->and($rfq->refresh()->sourcingCountdown(1))->toBeNull();

    test()->travelTo($colombo('2026-10-05 15:00'));
    changeRfqStatus($ops, $rfq, ['status' => 'Pending']);

    test()->travelTo($colombo('2026-10-05 16:00'));

    // 09:00–10:00 and 15:00–16:00: the five hours on hold don't count, and
    // it's the one round carrying on — the countdown too — not a new one.
    $time = $rfq->refresh()->timeSpent()['roles']['Sourcing'];
    expect($time)->toMatchArray(['seconds' => 2 * 3600, 'rounds' => 1, 'reworks' => 0])
        ->and($rfq->sourcingCountdown(1)['remaining'])->toBe(4 * 3600 - 2 * 3600)
        ->and($rfq->sourcingDeadline())->toMatchArray(['remaining' => 2 * 3600, 'late_rounds' => 0, 'exceeded' => false]);
});

it('credits Data Entry\'s time before a hold to whoever finishes the part after it', function () {
    $ops = userWithRole('Senior Operations');
    $dataEntry = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);

    changeRfqStatus($ops, $rfq, ['status' => 'On Hold', 'reason' => 'Paused']);
    changeRfqStatus($ops, $rfq, ['status' => 'Pending']);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    $stretches = RfqStep::query()->where('step', 'data_entry')->oldest('id')->get();

    expect($stretches)->toHaveCount(2)
        ->and($stretches->pluck('resumed')->all())->toBe([false, true])
        ->and($stretches->pluck('worked_by')->all())->toBe([$dataEntry->id, $dataEntry->id])
        ->and($rfq->refresh()->timeSpent()['roles']['Data Entry'])->toMatchArray(['rounds' => 1, 'reworks' => 0]);
});

it('keeps an RFQ on hold through an edit — only resuming sets it going', function () {
    $ops = userWithRole('Senior Operations');
    $admin = userWithRole('Admin');
    $rfq = Rfq::factory()->create();
    changeRfqStatus($ops, $rfq, ['status' => 'On Hold', 'reason' => 'Paused']);

    // The edit form shows it, but can't choose it.
    test()->actingAs($admin)->get(route('admin.rfqs.index'))->assertOk()
        ->assertSee('<option value="On Hold" disabled>', false);

    test()->actingAs($admin)->put(route('admin.rfqs.update', $rfq), [
        'wc_number' => $rfq->wc_number,
        'rfq_number' => $rfq->rfq_number,
        'priority_level' => 'High',
        'number_of_items' => 3,
        'subject' => 'Changed while on hold',
    ])->assertSessionHasNoErrors();

    expect($rfq->refresh())
        ->status->toBe('On Hold')
        ->subject->toBe('Changed while on hold');
});

it('gives Senior Operations On Hold and Cancelled pages in the sidebar, with how many', function () {
    $ops = userWithRole('Senior Operations');
    changeRfqStatus($ops, Rfq::factory()->create(), ['status' => 'On Hold', 'reason' => 'Paused']);
    changeRfqStatus($ops, Rfq::factory()->create(), ['status' => 'On Hold', 'reason' => 'Paused']);
    changeRfqStatus($ops, Rfq::factory()->create(), ['status' => 'Cancelled', 'reason' => 'Duplicate']);

    test()->actingAs($ops)->get(route('admin.dashboard'))->assertOk()
        ->assertSee(e(route('admin.rfqs.index', ['status' => 'On Hold'])), false)
        ->assertSee(e(route('admin.rfqs.index', ['status' => 'Cancelled'])), false)
        ->assertSee('title="2 on hold">2</span>', false)
        ->assertSee('title="1 cancelled">1</span>', false);

    expect(Rfq::queueCounts())->toMatchArray(['on_hold' => 2, 'cancelled' => 1]);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard'))->assertOk()
        ->assertSee(e(route('admin.rfqs.index', ['status' => 'On Hold', 'role' => 'senior-operations'])), false);
});

it('gives an RFQ kept whole on Senior Operations\' Assigned tab the option on its one task line, not its row', function () {
    $ops = userWithRole('Senior Operations');
    $assigned = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ12010', 'subject' => 'Roof pumps']), [1 => userWithRole('Sourcing')]);
    $unassigned = Rfq::factory()->create();
    $changeStatusUrl = 'data-action="'.route('admin.rfqs.change-status', $assigned).'"';

    $html = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'assigned']))->assertOk()
        ->assertSee('id="rfqStatusModal"', false)
        ->assertSee('data-label="RFQ12010 — Roof pumps"', false)
        ->assertDontSee('data-action="'.route('admin.rfqs.change-status', $unassigned).'"', false)
        ->getContent();

    $assignedTab = Str::betweenFirst($html, 'id="rfq-ops-assigned"', '</table>');
    $taskLine = Str::betweenFirst($assignedTab, '<tr class="rfq-part-row">', '</tr>');
    expect($taskLine)->toContain('Whole task')
        ->toContain($changeStatusUrl)
        ->and(substr_count($assignedTab, 'data-status="On Hold"'))->toBe(1)
        ->and(substr_count($assignedTab, 'data-status="Cancelled"'))->toBe(1)
        ->and(Str::betweenFirst($assignedTab, '<tr class="rfq-group-head">', '</tr>'))->not->toContain('js-rfq-status');

    // Back to the Assigned tab once it's done — off it, now it's on hold.
    $assignedUrl = route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'assigned']);
    test()->actingAs($ops)->from($assignedUrl)
        ->patch(route('admin.rfqs.change-status', $assigned), ['status' => 'On Hold', 'reason' => 'Client paused it', 'status_rfq_id' => $assigned->id])
        ->assertRedirect($assignedUrl);

    expect($assigned->refresh()->status)->toBe('On Hold');

    test()->actingAs($ops)->get($assignedUrl)->assertOk()
        ->assertDontSee($changeStatusUrl, false);
});

it('reopens the shared popup against the same RFQ when the reason is left out', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ12011', 'subject' => 'Boiler']), [1 => userWithRole('Sourcing')]);
    $assignedUrl = route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'assigned']);

    test()->actingAs($ops)->from($assignedUrl)
        ->patch(route('admin.rfqs.change-status', $rfq), [
            'status' => 'Cancelled',
            'reason' => '',
            'status_rfq_id' => $rfq->id,
            'status_label' => 'RFQ12011 — Boiler',
        ])
        ->assertRedirect($assignedUrl);

    test()->actingAs($ops)->get($assignedUrl)->assertOk()
        ->assertSee('id="rfqStatusForm" action="'.route('admin.rfqs.change-status', $rfq).'"', false)
        ->assertSee('<div class="text-muted-soft small" id="rfqStatusTarget">RFQ12011 — Boiler</div>', false)
        ->assertSee('<input type="hidden" name="status" value="Cancelled">', false)
        ->assertSee('Say why.');

    expect($rfq->refresh()->status)->toBe('Pending');
});
