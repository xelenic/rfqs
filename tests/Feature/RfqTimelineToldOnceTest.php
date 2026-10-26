<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * What $viewer's timeline of $rfq shows, as [type, action, body or detail]
 * per entry — the RFQ's creation and assignment left out.
 *
 * @return list<array{0: string, 1: ?string, 2: ?string}>
 */
function toldTimeline(Rfq $rfq, User $viewer): array
{
    return collect($rfq->refresh()->activityTimelineFor($viewer))
        ->reject(fn (array $entry) => in_array($entry['type'], ['created', 'sourcing_assigned', 'operations_assigned'], true))
        ->map(fn (array $entry) => [$entry['type'], $entry['comment']?->action, $entry['comment']?->body ?? $entry['detail']])
        ->values()
        ->all();
}

it('tells a General Manager sending a part back to Sourcing once — the rejection, naming whose part', function () {
    $amila = tap(userWithRole('Sourcing'))->update(['name' => 'Amila Jayathissa']);
    $tina = tap(userWithRole('General Manager'))->update(['name' => 'Tina Liyanage']);
    $rfq = splitAmong(Rfq::factory()->create(['stage' => 'gm_review']), [1 => $amila]);

    $rfq->rejectPartToStage(1, 'sourcing', 'Please re-verify the item price with the quoted packaging.', $tina, 'gm_review');

    // Recorded every way, as before: the rejection, the part's return, and the RFQ's columns.
    expect($rfq->refresh()->comments->pluck('action')->all())->toBe(['returned_to_sourcing', 'rejected'])
        ->and($rfq->rejected_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(1)->pivot->returned_at)->not->toBeNull();

    // Told once: the rejection's comment, which names whose part went back.
    expect(toldTimeline($rfq, $tina))->toBe([
        ['comment', 'rejected', 'Please re-verify the item price with the quoted packaging.'],
    ]);
    $rejection = collect($rfq->activityTimelineFor($tina))->firstWhere('type', 'comment');
    expect($rejection['parts_sent_back'])->toBe(['Amila Jayathissa\'s part']);

    $html = test()->actingAs($tina)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();
    $timeline = Str::after($html, 'Activity Timeline (');

    expect($timeline)->toStartWith('1 comments)')
        ->and(substr_count($timeline, 'Please re-verify the item price with the quoted packaging.'))->toBe(1)
        ->and(Str::betweenFirst($timeline, 'comment-action-danger', 'rfq-comment-time'))
        ->toContain('Rejected')
        ->toContain('Returned to Sourcing')
        ->toContain('Amila Jayathissa&#039;s part');

    // Amila doesn't see another role's rejection — she still sees her part came back, once.
    expect(toldTimeline($rfq, $amila))->toBe([
        ['sourcing_returned', null, 'Please re-verify the item price with the quoted packaging.'],
    ]);
});

it('tells a whole split RFQ sent back to Sourcing once, naming each part', function () {
    [$riley, $sam] = [tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']), tap(userWithRole('Sourcing'))->update(['name' => 'Sam Rivera'])];
    $head = userWithRole('Head of Business Development');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'stage' => 'head_of_bd_review']), [1 => $riley, 2 => $sam]);

    $rfq->rejectToStage('sourcing', 'The quotes do not add up', $head);

    expect(toldTimeline($rfq, $head))->toBe([
        ['comment', 'rejected', 'The quotes do not add up'],
    ])
        ->and(collect($rfq->activityTimelineFor($head))->firstWhere('type', 'comment')['parts_sent_back'])
        ->toBe(['RFQ1001-P1 of P2 · Riley Chen\'s part', 'RFQ1001-P2 of P2 · Sam Rivera\'s part']);
});

it('tells a rejection to another step once — its comment, not the step too', function () {
    $riley = userWithRole('Sourcing');
    $gm = userWithRole('General Manager');
    $rfq = splitAmong(Rfq::factory()->create(['stage' => 'gm_review']), [1 => $riley]);

    $rfq->rejectPartToStage(1, 'gm_assistant', 'Wrong letterhead', $gm, 'gm_review');

    expect(toldTimeline($rfq, $gm))->toBe([
        ['comment', 'rejected', 'Wrong letterhead'],
    ]);
});

it('tells Data Entry sending a part back once, and keeps returns of other moments apart', function () {
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $gm = userWithRole('General Manager');
    $rfq = splitAmong(Rfq::factory()->create(['stage' => 'gm_review']), [1 => $riley]);

    $rfq->returnSourcingPart(1, 'Missing prices', $morgan);

    // The return's comment stands for the step — for Riley too, as it's Data Entry's own action.
    expect(toldTimeline($rfq, $gm))->toBe([['comment', 'returned_to_sourcing', 'Missing prices']])
        ->and(toldTimeline($rfq, $riley))->toBe([['comment', 'returned_to_sourcing', 'Missing prices']]);

    // Later the General Manager sends it back with the same words: a moment of its own, told on its own.
    test()->travel(5)->minutes();
    $rfq->refresh()->rejectPartToStage(1, 'sourcing', 'Missing prices', $gm, 'gm_review');

    expect(toldTimeline($rfq, $gm))->toBe([
        ['comment', 'returned_to_sourcing', 'Missing prices'],
        ['comment', 'rejected', 'Missing prices'],
    ]);
});

it('shows a rejection back to Sourcing once on the part\'s quick view too', function () {
    $amila = userWithRole('Sourcing');
    $gm = userWithRole('General Manager');
    $rfq = splitAmong(Rfq::factory()->create(['stage' => 'gm_review']), [1 => $amila]);
    $rfq->rejectPartToStage(1, 'sourcing', 'Re-verify the packaging', $gm, 'gm_review');
    $comments = $rfq->refresh()->comments;

    expect(RfqComment::withoutRejectionRepeats($comments)->pluck('action')->all())->toBe(['rejected'])
        // Someone else's return, or other words, aren't part of it.
        ->and(RfqComment::withoutRejectionRepeats($comments->push(new RfqComment([
            'user_id' => $gm->id, 'action' => 'returned_to_sourcing', 'body' => 'Other words',
        ])->setAttribute('created_at', now())))->pluck('action')->all())->toBe(['rejected', 'returned_to_sourcing']);
});
