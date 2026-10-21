<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * RFQ8601 taken all the way to the Head of Business Development by Riley
 * (Sourcing) and Morgan (Data Entry) — Morgan given it by name, adding fewer
 * line items than asked — with a word from Senior Operations on the thread,
 * and a part of it put on hold and resumed by them along the way.
 *
 * @return array{rfq: Rfq, riley: User, morgan: User, ops: User}
 */
function throughEveryRole(): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $morgan = tap(userWithRole('Data Entry'))->update(['name' => 'Morgan Ito']);
    $ops = tap(userWithRole('Senior Operations'))->update(['name' => 'Olivia Park']);
    userWithRole('General Manager');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8601', 'subject' => 'Valves']), [1 => $riley]);

    $rfq->comments()->create(['user_id' => $ops->id, 'body' => 'Please prioritise this one']);
    $rfq->refresh()->changeStatus(Rfq::ON_HOLD, $ops, 'Client paused the order');
    $rfq->refresh()->changeStatus('Pending', $ops);

    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Three quotes in', 'line_items' => 24, 'data_entry_user_id' => $morgan->id]);
    startDataEntryOn($rfq, 1, $morgan);
    test()->actingAs($morgan)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'entries' => 22, 'comment' => 'Prices entered']);
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, $ops, 22);
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));

    return ['rfq' => $rfq->refresh(), 'riley' => $riley, 'morgan' => $morgan, 'ops' => $ops];
}

/**
 * The RFQ page's Activity Timeline, header and all, as $viewer sees it.
 */
function timelineCardFor(Rfq $rfq, User $viewer): string
{
    $html = test()->actingAs($viewer)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    return Str::betweenFirst($html, 'Activity Timeline', 'card-footer');
}

it('shows Sourcing only Sourcing\'s and Data Entry\'s part of the timeline — and the discussion', function () {
    ['rfq' => $rfq, 'riley' => $riley] = throughEveryRole();

    expect(timelineCardFor($rfq, $riley))
        ->toContain('Assigned to Sourcing')
        ->toContain('Assigned to Data Entry')
        ->toContain('Sent to Finalize by Data Entry')
        ->toContain('Finalized by Sourcing')
        ->toContain('Three quotes in')
        ->toContain('Prices entered')
        ->toContain('Please prioritise this one')
        // Nobody else's actions or alerts.
        ->not->toContain('RFQ Created')
        ->not->toContain('Approved by Senior Operations')
        ->not->toContain('Part approved by Senior Operations')
        ->not->toContain('Head of Business Development')
        ->not->toContain('Client paused the order')
        ->not->toContain('Put on hold')
        ->not->toContain('Line item count mismatch — alert sent');
});

it('shows Data Entry the same — with the alert that went to them', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = throughEveryRole();

    expect(timelineCardFor($rfq, $morgan))
        ->toContain('Sent to Finalize by Data Entry')
        ->toContain('Assigned in Data Entry — they were told')
        ->toContain('Please prioritise this one')
        ->not->toContain('Head of Business Development')
        ->not->toContain('Approved by Senior Operations')
        ->not->toContain('Client paused the order')
        ->not->toContain('Line item count mismatch — alert sent');
});

it('counts only the comments they see', function () {
    ['rfq' => $rfq, 'riley' => $riley] = throughEveryRole();

    // Sourcing's, Data Entry's and Senior Operations' word — not the hold and resume.
    expect(timelineCardFor($rfq, $riley))->toStartWith(' (3 comments)')
        ->and(timelineCardFor($rfq, userWithRole('Admin')))->toStartWith(' (5 comments)');
});

it('shows everyone else the whole timeline — Admin, other roles, and someone in Sourcing with another role', function (Closure $viewer) {
    ['rfq' => $rfq] = throughEveryRole();

    expect(timelineCardFor($rfq, $viewer()))
        ->toContain('RFQ Created')
        ->toContain('Approved by Senior Operations')
        ->toContain('Head of Business Development')
        ->toContain('Client paused the order')
        ->toContain('Line item count mismatch — alert sent');
})->with([
    'Admin' => [fn () => userWithRole('Admin')],
    'Senior Operations' => [fn () => userWithRole('Senior Operations')],
    'Sourcing and Senior Operations' => [fn () => userWithRole('Sourcing')->assignRole('Senior Operations')],
]);

it('knows who sees only Sourcing\'s and Data Entry\'s part', function (array $roles, bool $only) {
    $person = userWithRole($roles[0]);
    foreach (array_slice($roles, 1) as $role) {
        $person->assignRole($role);
    }

    expect($person->refresh()->seesOnlySourcingAndDataEntryTimeline())->toBe($only);
})->with([
    'Sourcing' => [['Sourcing'], true],
    'Data Entry' => [['Data Entry'], true],
    'both' => [['Sourcing', 'Data Entry'], true],
    'Sourcing and Admin' => [['Sourcing', 'Admin'], false],
    'Data Entry and GM Assistant' => [['Data Entry', 'GM Assistant'], false],
    'Senior Operations' => [['Senior Operations'], false],
]);
