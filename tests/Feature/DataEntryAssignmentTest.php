<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DataEntryAssigned;
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

    // Monday 5 October, 09:00 in Colombo — working time.
    test()->travelTo(CarbonImmutable::parse('2026-10-05 09:00', Setting::DEFAULT_TIMEZONE));
});

/**
 * RFQ $number, its one part with Riley in Sourcing, and two people in Data
 * Entry.
 *
 * @return array{rfq: Rfq, riley: User, morgan: User, sam: User}
 */
function withSourcingForDe(string $number = 'RFQ9501'): array
{
    $riley = userWithRole('Sourcing');

    return [
        'rfq' => splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'subject' => "Subject of {$number}"]), [1 => $riley]),
        'riley' => $riley,
        'morgan' => userWithRole('Data Entry'),
        'sam' => userWithRole('Data Entry'),
    ];
}

/**
 * $by assigns part 1 of $rfq to Data Entry — to $to by name, or anyone.
 */
function assignToDe(Rfq $rfq, User $by, ?User $to = null): TestResponse
{
    return test()->actingAs($by)->patch(route('admin.rfqs.complete-sourcing', $rfq), array_filter([
        'part' => 1,
        'comment' => 'Prices are in',
        'data_entry_user_id' => $to?->id,
    ]));
}

function deReadyList(User $viewer): string
{
    return test()->actingAs($viewer)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
}

it('lets Sourcing pick someone in Data Entry as they assign a part — each with what\'s already theirs', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan, 'sam' => $sam] = withSourcingForDe();
    $busy = withSourcingForDe('RFQ9502');
    assignToDe($busy['rfq'], $busy['riley'], $morgan);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('name="data_entry_user_id"', false)
        ->assertSee('Anyone in Data Entry — whoever starts it first')
        ->assertSee(e($morgan->name).' — 1 waiting', false)
        ->assertSee(e($sam->name).' — free', false);
});

it('gives the part to them by name — recorded, said on the thread, and told in their bell', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = withSourcingForDe();

    assignToDe($rfq, $riley, $morgan)
        ->assertRedirect()
        ->assertSessionHas('status', "Assigned to {$morgan->name} in Data Entry.");

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->data_entry_assigned_to)->toBe($morgan->id)
        ->and($part->completed_at)->not->toBeNull()
        ->and(RfqComment::query()->where('action', 'sourcing_completed')->sole()->actionContext())->toBe("to {$morgan->name}");

    $alert = $morgan->notifications()->sole();
    expect($alert->type)->toBe(DataEntryAssigned::class)
        ->and($alert->data['message'])->toBe("{$riley->name} assigned RFQ9501 to you — Subject of RFQ9501");

    test()->actingAs($morgan)->get(route('admin.dashboard'))->assertSee("{$riley->name} assigned RFQ9501 to you");
    test()->actingAs($morgan)->get(route('admin.notifications.open', $alert->id))
        ->assertRedirect(route('admin.rfqs.index', ['status' => 'Pending']));
});

it('refuses someone who isn\'t in Data Entry, assigning nothing', function () {
    ['rfq' => $rfq, 'riley' => $riley] = withSourcingForDe();

    assignToDe($rfq, $riley, userWithRole('Senior Operations'))
        ->assertSessionHas('error', 'Pick someone in Data Entry from the list, or leave it with anyone there.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->toBeNull();
});

it('leaves it with anyone in Data Entry when nobody\'s picked, as before', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan, 'sam' => $sam] = withSourcingForDe();

    assignToDe($rfq, $riley)->assertSessionHas('status');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBeNull()
        ->and($morgan->notifications()->count())->toBe(0)
        ->and(deReadyList($morgan))->toContain('RFQ9501')
        ->and(deReadyList($sam))->toContain('RFQ9501');
});

it('lists a part given by name only for them — and Admin, saying for whom', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan, 'sam' => $sam] = withSourcingForDe();
    assignToDe($rfq, $riley, $morgan);

    expect(Str::betweenFirst(deReadyList($morgan), '<td class="text-nowrap">RFQ9501</td>', '</tr>'))->toContain('Assigned to you')
        ->and(deReadyList($sam))->not->toContain('<td class="text-nowrap">RFQ9501</td>');

    $admin = test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'data-entry']))->assertOk()->getContent();
    expect($admin)->toContain('Assigned to '.e($morgan->name));

    // Their sidebar counts it for them alone.
    test()->actingAs($morgan)->get(route('admin.dashboard'))->assertSee('title="1 not marked complete"', false);
    test()->actingAs($sam)->get(route('admin.dashboard'))->assertDontSee('title="1 not marked complete"', false);
});

it('lets only whoever it was given to start it', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan, 'sam' => $sam] = withSourcingForDe();
    assignToDe($rfq, $riley, $morgan);

    // Not Sam's: the RFQ isn't one of theirs at all.
    test()->actingAs($sam)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])->assertForbidden();
    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_started_at)->toBeNull();

    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])
        ->assertSessionHas('status', 'Started RFQ9501 — your time on it is counting.');
});

it('counts someone idle only over parts that are theirs to start', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan, 'sam' => $sam] = withSourcingForDe();
    assignToDe($rfq, $riley, $morgan);
    test()->travelTo(CarbonImmutable::parse('2026-10-05 09:30', Setting::DEFAULT_TIMEZONE));

    expect($morgan->dataEntryIdleness())->not->toBeNull()
        ->and($sam->dataEntryIdleness())->toBeNull();
});

it('lets Sourcing pick again when the part comes back to them', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan, 'sam' => $sam] = withSourcingForDe();
    assignToDe($rfq, $riley, $morgan);
    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', $morgan);

    // The button remembers who had it.
    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))
        ->assertSee('data-de-assignee="'.$morgan->id.'"', false);

    assignToDe($rfq, $riley, $sam);
    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBe($sam->id);
});
