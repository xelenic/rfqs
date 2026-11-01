<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DataEntryAssigned;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
function reassignClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * $number, kept whole, handed to Data Entry — to $dataEntry by name, or to
 * anyone there.
 */
function partWithDataEntry(string $number, ?User $dataEntry = null): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'priority_level' => 'High']), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1, null, $dataEntry, 5);

    return $rfq->refresh();
}

/**
 * $by's Reassign of part 1 of $rfq.
 *
 * @param  array<string, mixed>  $choices
 */
function reassigns(User $by, Rfq $rfq, array $choices): TestResponse
{
    return test()->actingAs($by)->from(route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'assigned']))
        ->patch(route('admin.rfqs.reassign', $rfq), ['part' => 1] + $choices);
}

/**
 * The RFQ numbers on $person's Ready for Data Entry list.
 *
 * @return array<int, string>
 */
function readyFor(User $person): array
{
    return test()->actingAs($person)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->viewData('bySourcingRfqs')->pluck('rfq_number')->all();
}

it('offers Reassign on the Assigned tab and the RFQ page, saying who has it in Data Entry', function () {
    $ops = userWithRole('Senior Operations');
    $morgan = tap(userWithRole('Data Entry'))->update(['name' => 'Morgan Ito']);
    $rfq = partWithDataEntry('RFQ9001', $morgan);

    [, $assignedTab] = operationsTabs(['tab' => 'assigned']);

    expect($assignedTab)->toContain('data-action="'.route('admin.rfqs.reassign', $rfq).'"')
        ->toContain('<i class="bi bi-person-gear"></i> Reassign')
        ->toContain('data-de-can-replace="1"')
        ->toContain('data-de-current-id="'.$morgan->id.'"')
        ->toContain('data-de-now="Morgan Ito — not started"')
        ->toContain('Data Entry: Morgan Ito — not started')
        ->toContain('name="data_entry_user_id"')
        ->toContain('<option value="anyone"');

    $page = test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    expect($page)->toContain('data-action="'.route('admin.rfqs.reassign', $rfq).'"')
        ->toContain('id="reassignModal"');

    // Nobody else gets it.
    test()->actingAs($morgan)->get(route('admin.rfqs.show', $rfq))->assertOk()->assertDontSee('js-reassign-part');
});

it('lists each part of a split under one Reassign on the RFQ page', function () {
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9002']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);

    $html = test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    expect(Str::betweenFirst($html, 'data-reassign-menu', '</ul>'))
        ->toContain('data-part="1"')
        ->toContain('data-part="2"');
});

it('gives a part waiting for its Start to someone else in Data Entry — off one list, onto the other, and they\'re told', function () {
    Notification::fake();
    $ops = userWithRole('Senior Operations');
    [$morgan, $kim] = [userWithRole('Data Entry'), userWithRole('Data Entry')];
    $rfq = partWithDataEntry('RFQ9003', $morgan);

    expect(readyFor($morgan))->toBe(['RFQ9003'])->and(readyFor($kim))->toBe([]);

    test()->travel(5)->minutes();
    reassigns($ops, $rfq, ['data_entry_user_id' => $kim->id, 'reason' => 'Morgan is on leave'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "Replaced Data Entry on RFQ9003: {$morgan->name} → {$kim->name}.");

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;
    $comment = $rfq->comments->firstWhere('action', 'reassigned');

    expect($pivot->data_entry_assigned_to)->toBe($kim->id)
        ->and($pivot->data_entry_reassigned_at)->not->toBeNull()
        ->and($comment->body)->toBe("Data Entry replaced: from {$morgan->name} to {$kim->name}. Morgan is on leave")
        ->and($comment->user_id)->toBe($ops->id)
        ->and(readyFor($morgan))->toBe([])
        ->and(readyFor($kim))->toBe(['RFQ9003'])
        ->and(PrivateMessage::query()->where('recipient_id', $kim->id)->where('rfq_id', $rfq->id)->sole()->body)->toContain("Gave you RFQ9003 in Data Entry — it was {$morgan->name}'s")
        ->and(PrivateMessage::query()->where('recipient_id', $morgan->id)->where('rfq_id', $rfq->id)->latest('id')->first()->body)->toContain("Gave RFQ9003 to {$kim->name} in Data Entry — it's no longer yours");

    Notification::assertSentTo($kim, DataEntryAssigned::class);

    // It's Kim's to start, and says when it came to them.
    test()->actingAs($kim)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Reassigned to you · '.$pivot->data_entry_reassigned_at->toBusinessTimezone()->format('M d, g:i A'));
    // Morgan never worked on it, so it's not theirs to see now.
    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])
        ->assertForbidden();
    test()->actingAs($kim)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_started_by)->toBe($kim->id);
});

it('takes a part from whoever\'s on it: their time stays theirs, they\'re free, and the new person\'s countdown starts from their own Start', function () {
    $ops = userWithRole('Senior Operations');
    [$morgan, $kim] = [userWithRole('Data Entry'), userWithRole('Data Entry')];

    reassignClock('09:00');
    $rfq = partWithDataEntry('RFQ9004', $morgan);
    startDataEntryOn($rfq, 1, $morgan);
    $target = $rfq->refresh()->dataEntryCountdown(1)['target'];

    reassignClock('09:40');
    expect(RfqStep::runningDataEntryOf($morgan))->not->toBeNull()
        ->and($rfq->refresh()->dataEntryStanding(1)['label'])->toContain('started');

    reassigns($ops, $rfq, ['data_entry_user_id' => $kim->id])->assertSessionHasNoErrors();

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;
    $stretch = RfqStep::query()->where('rfq_id', $rfq->id)->where('step', 'data_entry')->sole();

    expect($pivot->data_entry_started_at)->toBeNull()
        ->and($pivot->data_entry_started_by)->toBeNull()
        ->and(RfqStep::runningDataEntryOf($morgan))->toBeNull()
        // Morgan's 40 minutes stay Morgan's, and it isn't finished.
        ->and($stretch->worked_by)->toBe($morgan->id)
        ->and($stretch->ended_at)->not->toBeNull()
        ->and($stretch->ended_by_role)->toBeFalse()
        ->and($rfq->dataEntryCountdown(1))->toBeNull()
        ->and($rfq->deadlines()['late'])->toBe([]);

    // Kim starts afresh: the whole target, from their Start.
    reassignClock('10:00');
    startDataEntryOn($rfq, 1, $kim);

    expect($rfq->refresh()->dataEntryCountdown(1)['remaining'])->toBe($target)
        ->and(RfqStep::query()->where('rfq_id', $rfq->id)->whereNull('ended_at')->sole()->worked_by)->toBe($kim->id);
});

it('clears a pause along with the Start', function () {
    [$morgan, $kim] = [userWithRole('Data Entry'), userWithRole('Data Entry')];
    $rfq = partWithDataEntry('RFQ9005', $morgan);
    startDataEntryOn($rfq, 1, $morgan);
    $rfq->refresh()->pauseDataEntryPart(1, $morgan, 'Waiting for the supplier');

    expect($rfq->refresh()->dataEntryStanding(1)['label'])->toContain('paused');

    reassigns(userWithRole('Senior Operations'), $rfq, ['data_entry_user_id' => $kim->id])->assertSessionHasNoErrors();

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;

    expect($pivot->isDataEntryPaused())->toBeFalse()
        ->and($pivot->data_entry_pause_reason)->toBeNull()
        ->and($rfq->partAwaitsDataEntryStart(1))->toBeTrue();
});

it('leaves a part with anyone in Data Entry, and gives one left with anyone to someone by name', function () {
    $ops = userWithRole('Senior Operations');
    [$morgan, $kim] = [userWithRole('Data Entry'), userWithRole('Data Entry')];
    $rfq = partWithDataEntry('RFQ9006', $morgan);

    reassigns($ops, $rfq, ['data_entry_user_id' => 'anyone'])
        ->assertSessionHas('status', "Replaced Data Entry on RFQ9006: {$morgan->name} → anyone there.");

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBeNull()
        ->and($rfq->dataEntryStanding(1)['label'])->toBe('Anyone in Data Entry — not started')
        ->and(readyFor($morgan))->toBe(['RFQ9006'])
        ->and(readyFor($kim))->toBe(['RFQ9006'])
        // Everyone there hears of it, Kim and Morgan alike.
        ->and(PrivateMessage::query()->where('recipient_id', $kim->id)->sole()->body)->toContain('Left RFQ9006 with anyone in Data Entry');

    reassigns($ops, $rfq, ['data_entry_user_id' => $kim->id])
        ->assertSessionHas('status', "Replaced Data Entry on RFQ9006: anyone there → {$kim->name}.");

    expect(readyFor($morgan))->toBe([])->and(readyFor($kim))->toBe(['RFQ9006']);
});

it('replaces Sourcing and Data Entry together', function () {
    Notification::fake();
    $ops = userWithRole('Senior Operations');
    $sam = userWithRole('Sourcing');
    [$morgan, $kim] = [userWithRole('Data Entry'), userWithRole('Data Entry')];
    $rfq = partWithDataEntry('RFQ9007', $morgan);
    $riley = $rfq->assigneeForPart(1);

    reassigns($ops, $rfq, ['user_id' => $sam->id, 'data_entry_user_id' => $kim->id, 'reason' => 'Team change'])
        ->assertSessionHas('status', "Replaced Sourcing on RFQ9007: {$riley->name} → {$sam->name}. Replaced Data Entry on RFQ9007: {$morgan->name} → {$kim->name}.");

    expect($rfq->refresh()->assigneeForPart(1)->id)->toBe($sam->id)
        ->and($rfq->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBe($kim->id)
        ->and($rfq->comments->where('action', 'reassigned')->pluck('body')->all())->toBe([
            "Sourcing replaced: from {$riley->name} to {$sam->name}. Team change",
            "Data Entry replaced: from {$morgan->name} to {$kim->name}. Team change",
        ]);
});

it('refuses what makes no sense, changing nothing — even when the other choice is fine', function () {
    $ops = userWithRole('Senior Operations');
    $sam = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $rfq = partWithDataEntry('RFQ9008', $morgan);
    $riley = $rfq->assigneeForPart(1);

    reassigns($ops, $rfq, [])
        ->assertSessionHasErrorsIn('reassign', ['user_id' => 'Pick who takes over Sourcing or Data Entry.']);
    reassigns($ops, $rfq, ['user_id' => $sam->id, 'data_entry_user_id' => $morgan->id])
        ->assertSessionHasErrorsIn('reassign', ['data_entry_user_id' => 'They already have it — pick someone else.']);
    reassigns($ops, $rfq, ['user_id' => $sam->id, 'data_entry_user_id' => $sam->id])
        ->assertSessionHasErrorsIn('reassign', ['data_entry_user_id' => 'Pick someone from Data Entry.']);

    expect($rfq->refresh()->assigneeForPart(1)->id)->toBe($riley->id)
        ->and($rfq->comments()->where('action', 'reassigned')->count())->toBe(0);

    reassigns($ops, $rfq, ['data_entry_user_id' => 'anyone'])->assertSessionHasNoErrors();
    reassigns($ops, $rfq, ['data_entry_user_id' => 'anyone'])
        ->assertSessionHasErrorsIn('reassign', ['data_entry_user_id' => 'It\'s already with anyone in Data Entry.']);
});

it('stops choosing Data Entry once the part\'s been sent to finalize, Sourcing still replaceable', function () {
    $ops = userWithRole('Senior Operations');
    $morgan = userWithRole('Data Entry');
    $rfq = partWithDataEntry('RFQ9009', $morgan);
    startDataEntryOn($rfq, 1, $morgan);
    $rfq->refresh()->completeDataEntryPart(1, $morgan, null, 5);

    expect($rfq->refresh()->canReplaceDataEntry(1))->toBeFalse()
        ->and($rfq->dataEntryStanding(1)['label'])->toBe("Finished — {$morgan->name} sent it to finalize.");
    reassigns($ops, $rfq, ['data_entry_user_id' => userWithRole('Data Entry')->id])->assertStatus(422);

    reassigns($ops, $rfq, ['user_id' => userWithRole('Sourcing')->id])->assertSessionHasNoErrors();
});

it('names someone in Data Entry while the part\'s still with Sourcing: told by message, on nobody\'s list yet', function () {
    Notification::fake();
    $ops = userWithRole('Senior Operations');
    $morgan = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9013']), [1 => userWithRole('Sourcing')]);

    expect($rfq->dataEntryStanding(1))->toMatchArray(['can_replace' => true, 'handed_over' => false, 'current_id' => null, 'label' => 'Nobody named — Sourcing picks who when they hand it over']);

    reassigns($ops, $rfq, ['data_entry_user_id' => $morgan->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "Named {$morgan->name} for Data Entry on RFQ9013 — it goes to them once Sourcing hands it over.");

    expect($rfq->refresh()->dataEntryStanding(1)['label'])->toBe("{$morgan->name} — named, for when Sourcing hands it over")
        ->and($rfq->assigneeForPart(1)->pivot->isDataEntryChosenByOperations())->toBeTrue()
        ->and($rfq->comments->firstWhere('action', 'reassigned')->body)->toBe("Data Entry assigned: {$morgan->name}, for when Sourcing hands it over.")
        ->and(PrivateMessage::query()->where('recipient_id', $morgan->id)->sole()->body)->toContain('Named you for RFQ9013 in Data Entry')
        ->and(readyFor($morgan))->toBe([])
        ->and(Rfq::dataEntryWaitingSince($morgan->id))->toBeEmpty();

    Notification::assertNothingSent();

    // On the Assigned tab, and they can open the RFQ the message leads to.
    [, $assignedTab] = operationsTabs(['tab' => 'assigned']);
    expect($assignedTab)->toContain('data-data-entry-named')
        ->toContain('data-de-handed="0"');
    test()->actingAs($morgan)->get(route('admin.rfqs.show', $rfq))->assertOk();
});

it('sends it to whoever Senior Operations named, whatever Sourcing picks — their popup shows it locked', function () {
    Notification::fake();
    $ops = userWithRole('Senior Operations');
    [$morgan, $kim] = [userWithRole('Data Entry'), userWithRole('Data Entry')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9014']), [1 => userWithRole('Sourcing')]);
    $riley = $rfq->assigneeForPart(1);

    reassigns($ops, $rfq, ['data_entry_user_id' => $morgan->id])->assertSessionHasNoErrors();

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('data-de-assignee="'.$morgan->id.'"', false)
        ->assertSee('data-de-named="'.e($morgan->name).'"', false)
        ->assertSee('id="complete-data-entry-named"', false);

    // Even picking someone else, as a tampered form might.
    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), [
        'part' => 1, 'comment' => 'Prices are in', 'line_items' => 5, 'data_entry_user_id' => $kim->id,
    ])->assertSessionHas('status', "Assigned to {$morgan->name} in Data Entry.");

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBe($morgan->id)
        ->and($rfq->comments->firstWhere('action', 'sourcing_completed')->meta['assigned_to'])->toBe($morgan->name)
        ->and(readyFor($morgan))->toBe(['RFQ9014'])
        ->and(readyFor($kim))->toBe([]);

    Notification::assertSentTo($morgan, DataEntryAssigned::class);
    Notification::assertNotSentTo($kim, DataEntryAssigned::class);

    // Assigned, not "reassigned": it's the first it's come to them.
    test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Assigned to you')
        ->assertDontSee('Reassigned to you');
});

it('can change the person named, or give the pick back to Sourcing', function () {
    $ops = userWithRole('Senior Operations');
    [$morgan, $kim, $dana] = [userWithRole('Data Entry'), userWithRole('Data Entry'), userWithRole('Data Entry')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9015']), [1 => userWithRole('Sourcing')]);
    $riley = $rfq->assigneeForPart(1);

    reassigns($ops, $rfq, ['data_entry_user_id' => $morgan->id]);
    reassigns($ops, $rfq, ['data_entry_user_id' => $morgan->id])
        ->assertSessionHasErrorsIn('reassign', ['data_entry_user_id' => 'They already have it — pick someone else.']);

    reassigns($ops, $rfq, ['data_entry_user_id' => $kim->id])->assertSessionHasNoErrors();

    expect($rfq->refresh()->dataEntryHolderId(1))->toBe($kim->id)
        ->and($rfq->comments->where('action', 'reassigned')->last()->body)->toBe("Data Entry replaced: from {$morgan->name} to {$kim->name}, for when Sourcing hands it over.")
        ->and(PrivateMessage::query()->where('recipient_id', $morgan->id)->latest('id')->first()->body)->toContain("Gave RFQ9015 to {$kim->name} in Data Entry");

    reassigns($ops, $rfq, ['data_entry_user_id' => 'anyone'])
        ->assertSessionHas('status', 'Sourcing picks who has RFQ9015 in Data Entry again.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->isDataEntryChosenByOperations())->toBeFalse()
        ->and($rfq->dataEntryHolderId(1))->toBeNull()
        ->and(PrivateMessage::query()->where('recipient_id', $kim->id)->latest('id')->first()->body)->toContain('Took you off RFQ9015 in Data Entry');

    // Sourcing's pick counts again.
    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), [
        'part' => 1, 'comment' => 'Prices are in', 'line_items' => 5, 'data_entry_user_id' => $dana->id,
    ])->assertSessionHas('status', "Assigned to {$dana->name} in Data Entry.");

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBe($dana->id);
});

it('can name whoever had it before it came back to Sourcing — that was only where their picker started', function () {
    $ops = userWithRole('Senior Operations');
    $morgan = userWithRole('Data Entry');
    $rfq = partWithDataEntry('RFQ9016', $morgan);
    $rfq->returnSourcingPart(1, 'Wrong supplier', $morgan);

    expect($rfq->refresh()->dataEntryHolderId(1))->toBeNull();

    reassigns($ops, $rfq, ['data_entry_user_id' => $morgan->id])->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->isDataEntryChosenByOperations())->toBeTrue();
});

it('lets only Senior Operations and Admin replace Data Entry, Admin as one of Senior Operations', function () {
    $kim = userWithRole('Data Entry');
    $rfq = partWithDataEntry('RFQ9010', userWithRole('Data Entry'));

    foreach (['Sourcing', 'Business Development', 'Data Entry'] as $role) {
        reassigns(userWithRole($role), $rfq, ['data_entry_user_id' => $kim->id])->assertForbidden();
    }

    $ops = userWithRole('Senior Operations');
    reassigns(userWithRole('Admin'), $rfq, ['data_entry_user_id' => $kim->id, 'acting_user_id' => $ops->id])->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBe($kim->id)
        ->and($rfq->comments->firstWhere('action', 'reassigned')->user_id)->toBe($ops->id);
});

it('counts the new person\'s wait from when they were given it, and keeps them through a return to Sourcing', function () {
    $ops = userWithRole('Senior Operations');
    [$morgan, $kim] = [userWithRole('Data Entry'), userWithRole('Data Entry')];

    reassignClock('09:00');
    $rfq = partWithDataEntry('RFQ9011', $morgan);

    reassignClock('11:00');
    reassigns($ops, $rfq, ['data_entry_user_id' => $kim->id])->assertSessionHasNoErrors();

    expect(Rfq::dataEntryWaitingSince($kim->id)->sole()->equalTo(now()))->toBeTrue()
        ->and($rfq->refresh()->assigneeForPart(1)->pivot->dataEntryReassignedAt())->not->toBeNull();

    // Back to Sourcing and handed over again: still Kim, whoever's picked, and simply assigned.
    reassignClock('11:30');
    $rfq->returnSourcingPart(1, 'Wrong supplier', $kim);
    $rfq->refresh()->completeSourcingPart(1, null, $morgan, 5);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBe($kim->id)
        ->and($rfq->assigneeForPart(1)->pivot->dataEntryReassignedAt())->toBeNull();
});

it('leaves Sourcing\'s pick theirs once Senior Operations left it with anyone', function () {
    [$morgan, $kim] = [userWithRole('Data Entry'), userWithRole('Data Entry')];
    $rfq = partWithDataEntry('RFQ9017', $morgan);

    reassigns(userWithRole('Senior Operations'), $rfq, ['data_entry_user_id' => 'anyone'])->assertSessionHasNoErrors();

    $rfq->refresh()->returnSourcingPart(1, 'Wrong supplier', $kim);
    $rfq->refresh()->completeSourcingPart(1, null, $kim, 5);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBe($kim->id)
        ->and($rfq->assigneeForPart(1)->pivot->data_entry_reassigned_at)->toBeNull();
});

it('reopens the popup on the same part, saying where Data Entry stands, when it\'s refused', function () {
    $ops = userWithRole('Senior Operations');
    $morgan = tap(userWithRole('Data Entry'))->update(['name' => 'Morgan Ito']);
    $rfq = partWithDataEntry('RFQ9012', $morgan);

    reassigns($ops, $rfq, ['data_entry_user_id' => $morgan->id, 'reassign_rfq_id' => $rfq->id, 'reassign_target' => 'RFQ9012']);

    $html = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'assigned']))->assertOk()
        ->assertSee('They already have it — pick someone else.')
        ->assertSee('getElementById(\'reassignModal\')).show()', false)
        ->getContent();

    expect($html)->toMatch('/id="reassignForm"[^>]*action="'.preg_quote(route('admin.rfqs.reassign', $rfq), '/').'"/')
        ->toContain('id="reassign-de-now">Morgan Ito — not started<');
});
