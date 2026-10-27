<?php

use App\Models\Rfq;
use App\Models\User;
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
 * What a page shows of itself: its <main>, less the inert JSON (the
 *
 * @mention list) and pickers of everyone (Replace, Assign).
 */
function shownOf(TestResponse $response): string
{
    return preg_replace(['#<script\b[^>]*>.*?</script>#s', '#<select\b[^>]*>.*?</select>#s', '#<template\b[^>]*>.*?</template>#s'], '', pageMain($response));
}

/**
 * RFQ9911 split three ways — Pat, Riley, Sam — with part 2 finalized (to
 * Senior Operations' review) and part 1 sent back to Sourcing by Data Entry.
 *
 * @return array{rfq: Rfq, riley: User}
 */
function partTwoFinalized(): array
{
    $people = collect(['Pat Park', 'Riley Chen', 'Sam Rivera'])->map(fn (string $name) => tap(userWithRole('Sourcing'))->update(['name' => $name]));
    $rfq = splitAmong(Rfq::factory()->create([
        'rfq_number' => 'RFQ9911', 'subject' => 'Pumps',
        'operations_assigned_by' => userWithRole('Senior Operations')->id, 'operations_assigned_at' => now(),
    ]), [1 => $people[0], 2 => $people[1], 3 => $people[2]]);
    $dataEntry = userWithRole('Data Entry');

    $rfq->refresh()->completeSourcingPart(1, null, null, 6);
    $rfq->refresh()->returnSourcingPart(1, 'Part 1 supplier sheet is wrong', $dataEntry, 2);
    $rfq->refresh()->completeSourcingPart(2, null, null, 8);
    $rfq->refresh()->completeDataEntryPart(2, $dataEntry, 'Part 2 entered', 8);
    $rfq->refresh()->finalizePart(2, 8);
    $rfq->comments()->create(['user_id' => $people[1]->id, 'body' => 'A word for everyone on the RFQ']);

    return ['rfq' => $rfq->refresh(), 'riley' => $people[1]];
}

it('shows only that part\'s details on the RFQ page — its step details, its Sourcing member, its number', function () {
    ['rfq' => $rfq] = partTwoFinalized();

    $main = shownOf(test()->actingAs(userWithRole('Senior Operations'))->get($rfq->detailsUrl(2))->assertOk()
        ->assertSee('<title>RFQ9911-P2 of P3', false)
        ->assertSee('Showing <strong>RFQ9911-P2 of P3</strong> only — Riley Chen&#039;s part', false));

    expect($main)->toContain('RFQ9911-P2 of P3')
        ->not->toContain('RFQ9911-P1 of P3')
        ->not->toContain('RFQ9911-P3 of P3')
        ->not->toContain('Pat Park')
        ->not->toContain('Sam Rivera');

    // The whole RFQ, as before.
    $whole = shownOf(test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.show', $rfq))->assertOk());
    expect($whole)->toContain('RFQ9911-P1 of P3')->toContain('Pat Park')->toContain('Sam Rivera');
});

it('keeps to that part\'s timeline — and the RFQ\'s own, and the discussion — not the other parts\'', function () {
    ['rfq' => $rfq] = partTwoFinalized();

    test()->actingAs(userWithRole('Admin'))->get($rfq->detailsUrl(2))->assertOk()
        ->assertSee('Part 2 entered')
        ->assertSee('A word for everyone on the RFQ')
        ->assertDontSee('Part 1 supplier sheet is wrong');

    $timeline = collect($rfq->activityTimelineFor(userWithRole('Admin'), 2));
    expect($timeline->map(fn (array $entry) => Rfq::timelineEntryPart($entry))->unique()->sort()->values()->all())->toBe([null, 2])
        ->and(collect($rfq->activityTimelineFor(userWithRole('Admin')))->map(fn (array $entry) => Rfq::timelineEntryPart($entry))->unique()->filter()->sort()->values()->all())->toBe([1, 2, 3]);

    // The Admin's whole RFQ has them all.
    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', ['rfq' => $rfq, 'part' => 2, 'flow' => 'whole']))->assertOk()
        ->assertSee('Part 1 supplier sheet is wrong');
});

it('keeps another part\'s rejection off a part\'s page — its timeline step and its step details — but shows it on its own', function () {
    ['rfq' => $rfq] = partTwoFinalized();
    $ops = userWithRole('Senior Operations');
    $rfq->refresh()->completeSourcingPart(3, null, null, 4);
    $rfq->refresh()->completeDataEntryPart(3, userWithRole('Data Entry'), 'Part 3 entered', 4);
    $rfq->refresh()->finalizePart(3, 4);

    // Senior Operations rejects part 3 back to Data Entry — the RFQ's latest rejection.
    $rfq->refresh()->rejectPartToStage(3, 'data_entry', 'Part 3 totals are off', $ops, 'senior_ops_review');
    $admin = userWithRole('Admin');

    expect(shownOf(test()->actingAs($admin)->get($rfq->refresh()->detailsUrl(2))->assertOk()))
        ->not->toContain('Part 3 totals are off')
        ->and(collect($rfq->activityTimelineFor($admin, 2))->pluck('type'))->not->toContain('rejected');

    // On part 3's own page, and the whole RFQ's, it's there.
    expect(shownOf(test()->actingAs($admin)->get($rfq->detailsUrl(3))->assertOk()))->toContain('Part 3 totals are off')
        ->and(shownOf(test()->actingAs($admin)->get(route('admin.rfqs.show', ['rfq' => $rfq, 'part' => 2, 'flow' => 'whole']))->assertOk()))->toContain('Part 3 totals are off');

    // A rejection of the whole RFQ is every part's — told once, by its comment.
    $rfq->refresh()->rejectToStage('sourcing', 'Every quote is stale', $ops, 'senior_ops_review');
    foreach ([1, 2, 3] as $part) {
        expect(collect($rfq->refresh()->activityTimelineFor($admin, $part))->where('type', 'comment')->map(fn (array $entry) => $entry['comment']->body))
            ->toContain('Every quote is stale');
    }
});

it('offers the decisions on that part alone — never the whole RFQ\'s', function () {
    ['rfq' => $rfq] = partTwoFinalized();
    $ops = userWithRole('Senior Operations');

    $html = test()->actingAs($ops)->get($rfq->detailsUrl(2))->assertOk()
        ->assertSee('id="seniorOpsApproveModal"', false)
        ->assertSee('id="rejectRfqModal"', false)
        ->getContent();

    expect(Str::betweenFirst($html, 'js-senior-ops-approve"', '</button>'))
        ->toContain('data-action="'.route('admin.rfqs.approve-senior-ops-part', $rfq).'"')
        ->toContain('data-part="2"')
        ->and(Str::betweenFirst($html, 'data-action="'.route('admin.rfqs.reject-senior-ops', $rfq).'"', '>'))->toContain('data-part="2"')
        ->and($html)->toContain('Put P2 on hold')->toContain('Cancel P2');

    // Part 1 isn't Senior Operations' to decide yet.
    expect(test()->actingAs($ops)->get($rfq->detailsUrl(1))->assertOk()->getContent())->not->toContain('js-senior-ops-approve"');
});

it('counts the step tabs and the current step by that part', function () {
    ['rfq' => $rfq] = partTwoFinalized();

    $html = test()->actingAs(userWithRole('Admin'))->get($rfq->detailsUrl(2))->assertOk()->getContent();

    // Part 2 is through finalize and waiting on Senior Operations: their tab is the open one.
    expect(Str::betweenFirst($html, 'id="step-pane-senior_ops"', '>'))->toBeString()
        ->and($html)->toMatch('/class="tab-pane fade show active" id="step-pane-senior_ops"/');
});

it('counts the time spent on that part alone', function () {
    ['rfq' => $rfq] = partTwoFinalized();
    $rfq->load('steps');

    $whole = $rfq->timeSpent();
    $two = $rfq->timeSpent(part: 2);

    expect(array_keys($whole['roles']['Sourcing']['parts']))->toBe([1, 2, 3])
        ->and(array_keys($two['roles']['Sourcing']['parts']))->toBe([2])
        ->and($two['roles']['Sourcing']['reworks'])->toBe(0)
        ->and($whole['roles']['Sourcing']['reworks'])->toBe(1);
});
