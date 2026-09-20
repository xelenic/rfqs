<?php

use App\Models\Rfq;
use App\Models\RfqAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['Sourcing', 'Senior Operations', 'Data Entry'] as $role) {
        Role::findOrCreate($role);
    }
});

function myPendingUrl(): string
{
    return route('admin.rfqs.index', ['status' => 'Pending']);
}

function myReturnsUrl(): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']);
}

/**
 * Riley's work: RFQ1001 split three ways — part 1 in progress, part 2 sent back
 * by Data Entry, part 3 done and with Data Entry — and RFQ1002, whose only part
 * is sent back too. Sam has a returned part of his own.
 *
 * @return array{riley: User, sam: User, dataEntry: User, split: Rfq, whole: Rfq}
 */
function rileysWork(): array
{
    [$riley, $sam, $dataEntry] = [userWithRole('Sourcing'), userWithRole('Sourcing'), userWithRole('Data Entry')];

    $split = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Split job', 'priority_level' => 'Medium']), [1 => $riley, 2 => $riley, 3 => $riley]);
    $split->completeSourcingPart(2);
    $split->refresh()->returnSourcingPart(2, 'Prices are missing', $dataEntry);
    $split->refresh()->completeSourcingPart(3);

    $whole = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1002', 'subject' => 'Sent back whole', 'priority_level' => 'Medium']), [1 => $riley]);
    $whole->completeSourcingPart(1);
    $whole->refresh()->returnSourcingPart(1, 'Wrong model', $dataEntry);

    $sams = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1003', 'subject' => 'Sam\'s job']), [1 => $sam]);
    $sams->completeSourcingPart(1);
    $sams->refresh()->returnSourcingPart(1, 'Missing a quote', $dataEntry);

    return ['riley' => $riley, 'sam' => $sam, 'dataEntry' => $dataEntry, 'split' => $split->refresh(), 'whole' => $whole->refresh()];
}

it('leaves the parts Data Entry sent back off My Pending RFQs, and keeps the rest', function () {
    ['riley' => $riley] = rileysWork();

    $html = test()->actingAs($riley)->get(myPendingUrl())->assertOk()->getContent();

    // The part still in progress and the one already handed on — not the returned one.
    expect($html)->toContain('<td class="text-nowrap">RFQ1001-P1 of P3</td>')
        ->toContain('<td class="text-nowrap">RFQ1001-P3 of P3</td>')
        ->not->toContain('<td class="text-nowrap">RFQ1001-P2 of P3</td>')
        // An RFQ whose only part was sent back isn't here at all…
        ->not->toContain('Sent back whole')
        // …and nor is why they were sent back.
        ->not->toContain('Returned by Data Entry')
        ->not->toContain('Prices are missing')
        // The quick-detail modals are for what's listed.
        ->toContain('id="rfq-detail-modal-')
        ->not->toContain('-p2"');
});

it('lists them on Returns instead, where they can be worked', function () {
    ['riley' => $riley, 'split' => $split, 'whole' => $whole] = rileysWork();

    $html = test()->actingAs($riley)->get(myReturnsUrl())->assertOk()->getContent();

    expect($html)->toContain('<td class="text-nowrap">RFQ1001-P2 of P3</td>')
        ->toContain('<td class="text-nowrap">RFQ1002</td>')
        ->toContain('Prices are missing')
        ->toContain('Wrong model')
        // Nothing of Sam's, nor what isn't returned.
        ->not->toContain('Sam&#039;s job')
        ->not->toContain('<td class="text-nowrap">RFQ1001-P1 of P3</td>')
        // Click a row for the part and its thread…
        ->toContain('class="js-de-sourcing-row" data-bs-target="#rfq-detail-modal-'.$split->id.'-p2"')
        ->toContain('id="rfq-detail-modal-'.$split->id.'-p2"')
        ->toContain('id="rfq-detail-modal-'.$whole->id.'-p1"')
        // …or Mark Complete, which asks for its comment and comes back here.
        ->toContain('data-action="'.route('admin.rfqs.complete-sourcing', $split).'"')
        ->toContain('data-redirect-view="returns"')
        ->toContain('id="completeModal"');
});

it('brings a reworked part back to Returns when it is completed from there, and off it', function () {
    ['riley' => $riley, 'split' => $split] = rileysWork();

    test()->actingAs($riley)
        ->patch(route('admin.rfqs.complete-sourcing', $split), ['part' => 2, 'comment' => 'Prices added', 'redirect_status' => 'Pending', 'redirect_view' => 'returns'])
        ->assertRedirect(myReturnsUrl())
        ->assertSessionHas('status');

    expect($split->refresh()->assigneeForPart(2)->pivot->completed_at)->not->toBeNull();

    // No longer a return: off Returns, and — done and with Data Entry — back on My Pending RFQs.
    test()->actingAs($riley)->get(myReturnsUrl())->assertOk()
        ->assertDontSee('<td class="text-nowrap">RFQ1001-P2 of P3</td>', false);
    test()->actingAs($riley)->get(myPendingUrl())->assertOk()
        ->assertSee('<td class="text-nowrap">RFQ1001-P2 of P3</td>', false);
});

it('still goes back to My Pending RFQs when a part is completed from there', function () {
    ['riley' => $riley, 'split' => $split] = rileysWork();

    test()->actingAs($riley)
        ->patch(route('admin.rfqs.complete-sourcing', $split), ['part' => 1, 'comment' => 'Done', 'redirect_status' => 'Pending'])
        ->assertRedirect(myPendingUrl());
});

it('counts what was sent back on the Returns link, and leaves it out of the pending count', function () {
    ['riley' => $riley, 'sam' => $sam] = rileysWork();
    $badge = fn (int $count, string $what) => '<span class="nav-link-count" title="'.$count.' '.$what.'">'.$count.'</span>';

    // Riley: one part still to complete; two sent back.
    $html = test()->actingAs($riley)->get(myPendingUrl())->assertOk()->getContent();

    expect($html)->toContain($badge(1, 'not marked complete'))
        ->toContain('<span class="nav-link-label">Returns</span>')
        ->toContain($badge(2, 'sent back by Data Entry'));

    // Sam's own, and only his.
    $samsHtml = test()->actingAs($sam)->get(myPendingUrl())->assertOk()->getContent();

    expect($samsHtml)->toContain($badge(1, 'sent back by Data Entry'))
        // Nothing left to complete, so no pending badge.
        ->not->toContain('not marked complete');

    // The badge is on every page, so it's seen wherever they are.
    test()->actingAs($riley)->get(myReturnsUrl())->assertOk()->assertSee($badge(2, 'sent back by Data Entry'), false);
});

it('drops the Returns count away when there is nothing sent back', function () {
    $riley = userWithRole('Sourcing');
    splitAmong(Rfq::factory()->create(), [1 => $riley]);

    $html = test()->actingAs($riley)->get(myPendingUrl())->assertOk()->getContent();

    expect($html)->toContain('<span class="nav-link-label">Returns</span>')
        ->not->toContain('sent back by Data Entry')
        ->toContain('title="1 not marked complete"');
});

it('keeps the pending and returned counts apart on Admin\'s tree, adding them on the Sourcing heading', function () {
    rileysWork();
    $riley = User::role('Sourcing')->first();
    // One pending part (Riley's P1) and three sent back (P2, RFQ1002 and Sam's).
    $counts = Rfq::queueCounts();

    expect($counts['sourcing_pending'])->toBe(1)
        ->and($counts['sourcing_returns'])->toBe(3);

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    // 1 + 3 waiting on Sourcing in all, each thing in one queue only.
    expect($html)->toContain('class="nav-link-count sidebar-group-count" title="4 waiting"');

    expect($riley)->not->toBeNull();
});

it('gives Admin\'s Sourcing pages the same split, all members\' parts at once', function () {
    rileysWork();
    $admin = userWithRole('Admin');

    $pending = test()->actingAs($admin)->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'sourcing']))->assertOk()->getContent();
    $returns = test()->actingAs($admin)->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'sourcing', 'view' => 'returns']))->assertOk()->getContent();

    // Open parts that haven't been sent back: Riley's P1 (P3 is already with Data Entry).
    expect($pending)->toContain('<td class="text-nowrap">RFQ1001-P1 of P3</td>')
        ->not->toContain('<td class="text-nowrap">RFQ1001-P2 of P3</td>')
        ->not->toContain('<td class="text-nowrap">RFQ1002</td>')
        ->not->toContain('Sam&#039;s job');

    expect($returns)->toContain('<td class="text-nowrap">RFQ1001-P2 of P3</td>')
        ->toContain('<td class="text-nowrap">RFQ1002</td>')
        ->toContain('Sam&#039;s job')
        ->not->toContain('<td class="text-nowrap">RFQ1001-P1 of P3</td>');
});

it('matches the SQL for a part that is not sent back to what its state says', function (array $pivot, bool $isReturned) {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $rfq->assignees()->updateExistingPivot($riley->id, $pivot);

    $listed = Rfq::whereHas('assignees', fn ($parts) => RfqAssignment::whereNotReturned($parts))->exists();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->progressState() === 'returned')->toBe($isReturned)
        ->and($listed)->toBe(! $isReturned);
})->with([
    'in progress' => [[], false],
    'sent back' => [['returned_at' => '2026-09-19 10:00:00'], true],
    'sent back, then completed again' => [['returned_at' => '2026-09-19 10:00:00', 'completed_at' => '2026-09-19 12:00:00'], false],
    'with Data Entry' => [['completed_at' => '2026-09-19 12:00:00'], false],
    'through Data Entry' => [['completed_at' => '2026-09-19 12:00:00', 'data_entry_completed_at' => '2026-09-19 13:00:00'], false],
]);
