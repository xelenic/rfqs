<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['Sourcing', 'Senior Operations', 'Data Entry', 'Head of Business Development', 'GM Assistant', 'General Manager', 'Business Development'] as $role) {
        Role::findOrCreate($role);
    }
});

function closingUrl(): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']);
}

function closedUrl(): string
{
    return route('admin.rfqs.index', ['status' => 'Completed']);
}

/**
 * The people who take an RFQ from Sourcing to closed.
 *
 * @return array{ops: User, dataEntry: User, head: User, assistant: User, gm: User, closer: User, sourcing: User}
 */
function closingPeople(): array
{
    return [
        'ops' => userWithRole('Senior Operations'),
        'dataEntry' => userWithRole('Data Entry'),
        'head' => userWithRole('Head of Business Development'),
        'assistant' => userWithRole('GM Assistant'),
        'gm' => userWithRole('General Manager'),
        'closer' => userWithRole('Business Development'),
        'sourcing' => userWithRole('Sourcing'),
    ];
}

/**
 * A two-way split, RFQ1001, both parts through Sourcing, Data Entry, Senior
 * Operations, the Head and GM Assistant, with the General Manager having
 * approved part 1 only — so part 1 is ready to close while part 2 waits on the
 * General Manager.
 *
 * @return array{0: Rfq, 1: array{ops: User, dataEntry: User, head: User, assistant: User, gm: User, closer: User, sourcing: User}}
 */
function splitWithPartOneApprovedByGm(): array
{
    $people = closingPeople();

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs', 'priority_level' => 'Medium']), [1 => $people['sourcing'], 2 => $people['sourcing']]);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $people['dataEntry']);
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
        $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
        $rfq->refresh()->recordGmAssistantPart($part, $people['assistant'], 'Acme Ltd', null);
    }
    $rfq->refresh()->approveGmPart(1, $people['gm']);

    return [$rfq->refresh(), $people];
}

/**
 * The row a part or RFQ number is listed in, on a page of them.
 */
function numberCell(string $number): string
{
    return '<td class="text-nowrap">'.$number.'</td>';
}

it('lists a part on Ready to Close as its own row as soon as the General Manager approves it, and only those', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();

    $html = test()->actingAs($people['closer'])->get(closingUrl())->assertOk()
        ->assertSee('Replace exit signs')
        ->assertSee(numberCell('RFQ1001-P1 of P2'), false)
        // Part 2 is still with the General Manager.
        ->assertDontSee(numberCell('RFQ1001-P2 of P2'), false)
        ->assertSee('by '.e($people['gm']->name), false)
        ->getContent();

    // Closing is for this part alone.
    expect($html)->toContain(route('admin.rfqs.close-part', $rfq))
        ->toContain('<input type="hidden" name="part" value="1">')
        ->not->toContain('<input type="hidden" name="part" value="2">')
        ->not->toContain('action="'.route('admin.rfqs.close', $rfq).'"');

    // Business Development doesn't see who holds a part: not in the list itself
    // (the page's hidden Assign modal is another matter, and not theirs to use).
    $table = substr($html, strpos($html, '<table'), strpos($html, '</table>') - strpos($html, '<table'));

    expect($table)->not->toContain('<th>Sourcing</th>')
        ->not->toContain(e($people['sourcing']->name));
});

it('has nothing to close until the General Manager has approved a part', function () {
    $people = closingPeople();
    $rfq = splitAmong(Rfq::factory()->create(['subject' => 'Not approved yet']), [1 => $people['sourcing']]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $people['dataEntry']);
    $rfq->refresh()->approveSeniorOpsPart(1, $people['ops']);
    $rfq->refresh()->approveHeadOfBdPart(1, $people['head']);
    $rfq->refresh()->recordGmAssistantPart(1, $people['assistant'], 'Acme Ltd', null);

    test()->actingAs($people['closer'])->get(closingUrl())->assertOk()
        ->assertDontSee('Not approved yet')
        ->assertSee('Nothing\'s ready to close right now.', false);

    $rfq->refresh()->approveGmPart(1, $people['gm']);

    test()->actingAs($people['closer'])->get(closingUrl())->assertSee('Not approved yet');
});

it('closes a part on its own and shows it on Closed RFQs while the rest of the RFQ carries on', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();

    test()->actingAs($people['closer'])->from(closingUrl())
        ->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1])
        ->assertRedirect(closingUrl())
        ->assertSessionHas('status', 'Closed RFQ1001-P1 of P2 — it\'s now in Closed RFQs.');

    $rfq->refresh();
    $part = $rfq->assigneeForPart(1)->pivot;

    expect($part->bd_closed_at)->not->toBeNull()
        ->and($part->bd_closed_by)->toBe($people['closer']->id)
        ->and($rfq->assigneeForPart(2)->pivot->bd_closed_at)->toBeNull()
        // The RFQ itself is still open.
        ->and($rfq->status)->toBe('Pending')
        ->and($rfq->stage)->not->toBe('closed')
        ->and($rfq->bd_closed_at)->toBeNull();

    // Gone from Ready to Close (the flash still names it, so look at the rows)…
    test()->actingAs($people['closer'])->get(closingUrl())->assertOk()
        ->assertDontSee(numberCell('RFQ1001-P1 of P2'), false)
        ->assertSee('Nothing\'s ready to close right now.', false);

    // …and on Closed RFQs, as a row of its own, with when and by whom.
    $html = test()->actingAs($people['closer'])->get(closedUrl())->assertOk()
        ->assertSee(numberCell('RFQ1001-P1 of P2'), false)
        ->assertDontSee(numberCell('RFQ1001-P2 of P2'), false)
        ->assertSee('<th>Closed</th>', false)
        ->assertSee('by '.e($people['closer']->name), false)
        ->getContent();

    expect($html)->not->toContain('<th>Status</th>');

    // The RFQ is still an ordinary Pending one everywhere else.
    test()->actingAs($people['closer'])->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Replace exit signs');
});

it('closes the RFQ once every part has been closed, and it stays on Closed RFQs part by part', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();
    $rfq->closePart(1, $people['closer']);
    $rfq->refresh()->approveGmPart(2, $people['gm']);

    expect($rfq->refresh()->stage)->toBe('bd_closing');

    test()->actingAs($people['closer'])->from(closingUrl())
        ->patch(route('admin.rfqs.close-part', $rfq), ['part' => 2])
        ->assertSessionHas('status', 'Closed RFQ1001-P2 of P2 — every part is closed, so the RFQ is closed.');

    $rfq->refresh();

    expect($rfq->status)->toBe('Completed')
        ->and($rfq->stage)->toBe('closed')
        ->and($rfq->bd_closed_by)->toBe($people['closer']->id)
        ->and($rfq->bd_closed_at)->not->toBeNull();

    // Both parts are on Closed RFQs, each on its own row — not replaced by one for the RFQ.
    test()->actingAs($people['closer'])->get(closedUrl())->assertOk()
        ->assertSee(numberCell('RFQ1001-P1 of P2'), false)
        ->assertSee(numberCell('RFQ1001-P2 of P2'), false);
});

it('closes the parts not yet closed when the whole RFQ is closed', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();
    $rfq->approveGmPart(2, $people['gm']);
    $rfq->refresh()->closePart(1, $people['closer']);
    $firstClosing = $rfq->refresh()->assigneeForPart(1)->pivot->bd_closed_at;

    test()->travel(5)->minutes();
    test()->actingAs($people['closer'])->patch(route('admin.rfqs.close', $rfq->refresh()))
        ->assertSessionHas('status', 'RFQ closed.');

    $rfq->refresh();

    expect($rfq->status)->toBe('Completed')
        ->and($rfq->assignees->every(fn (User $assignee) => $assignee->pivot->bd_closed_at !== null))->toBeTrue()
        // Part 1 keeps its own closing; part 2 gets this one.
        ->and($rfq->assigneeForPart(1)->pivot->bd_closed_at->equalTo($firstClosing))->toBeTrue()
        ->and($rfq->assigneeForPart(2)->pivot->bd_closed_by)->toBe($people['closer']->id);
});

it('takes an RFQ kept whole from Ready to Close to Closed RFQs as one row', function () {
    $people = closingPeople();
    $rfq = splitAmong(Rfq::factory()->create(['subject' => 'One piece of work']), [1 => $people['sourcing']]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $people['dataEntry']);
    $rfq->refresh()->approveSeniorOpsPart(1, $people['ops']);
    $rfq->refresh()->approveHeadOfBdPart(1, $people['head']);
    $rfq->refresh()->recordGmAssistantPart(1, $people['assistant'], 'Acme Ltd', null);
    $rfq->refresh()->approveGmPart(1, $people['gm']);

    // Its plain RFQ number, not a part's.
    test()->actingAs($people['closer'])->get(closingUrl())->assertOk()
        ->assertSee(numberCell($rfq->rfq_number), false);

    test()->actingAs($people['closer'])->from(closingUrl())
        ->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1])
        ->assertSessionHas('status', 'RFQ closed.');

    expect($rfq->refresh()->status)->toBe('Completed');

    // On Closed RFQs as the RFQ it is, with who closed it.
    test()->actingAs($people['closer'])->get(closedUrl())->assertOk()
        ->assertSee('One piece of work')
        ->assertSee($people['closer']->name)
        ->assertDontSee(numberCell($rfq->rfq_number), false);
});

it('lists an RFQ closed before parts were closed one by one as its parts, closed when it was', function () {
    $people = closingPeople();
    $closedAt = now()->subDays(3);
    $legacy = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Closed long ago', 'status' => 'Completed', 'stage' => 'closed', 'bd_closed_at' => $closedAt, 'bd_closed_by' => $people['closer']->id]), [1 => $people['sourcing'], 2 => $people['sourcing']]);
    $withNoParts = Rfq::factory()->create(['rfq_number' => 'RFQ1002', 'subject' => 'Closed with no parts', 'status' => 'Completed']);

    test()->actingAs($people['closer'])->get(closedUrl())->assertOk()
        ->assertSee(numberCell('RFQ1001-P1 of P2'), false)
        ->assertSee(numberCell('RFQ1001-P2 of P2'), false)
        ->assertSee($closedAt->format('M d, Y g:i A'))
        // An RFQ with no parts to speak of is just the RFQ.
        ->assertSee('Closed with no parts');
});

it('lists an RFQ at its closing stage with no part of its own to show, as a row for the whole', function () {
    $closer = userWithRole('Business Development');
    $rfq = Rfq::factory()->create(['stage' => 'bd_closing', 'subject' => 'Straight to closing']);

    test()->actingAs($closer)->get(closingUrl())->assertOk()
        ->assertSee('Straight to closing')
        ->assertSee(route('admin.rfqs.close', $rfq));
});

it('lets Admin work Ready to Close as Business Development would', function () {
    [$rfq] = splitWithPartOneApprovedByGm();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing', 'role' => 'business-development']))
        ->assertOk()
        ->assertSee(numberCell('RFQ1001-P1 of P2'), false);

    test()->actingAs($admin)->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1]);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->bd_closed_by)->toBe($admin->id);
});

it('refuses a closing that isn\'t Business Development\'s to give', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();
    $close = fn (User $user, array $data) => test()->actingAs($user)->patch(route('admin.rfqs.close-part', $rfq), $data);

    // Only Business Development (and Admin).
    $close($people['gm'], ['part' => 1])->assertForbidden();
    $close($people['head'], ['part' => 1])->assertForbidden();

    // A part the General Manager hasn't approved, one that doesn't exist, none at all.
    $close($people['closer'], ['part' => 2])->assertStatus(422);
    $close($people['closer'], ['part' => 9])->assertNotFound();
    $close($people['closer'], [])->assertSessionHasErrors('part');

    // And a part already closed.
    $close($people['closer'], ['part' => 1])->assertSessionHas('status');
    $close($people['closer'], ['part' => 1])->assertStatus(422);

    expect($rfq->refresh()->assigneeForPart(2)->pivot->bd_closed_at)->toBeNull();
});

it('leaves a closing alone that is repeated on the model', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();

    $rfq->closePart(1, $people['closer']);
    $first = $rfq->refresh()->assigneeForPart(1)->pivot->bd_closed_at;

    test()->travel(5)->minutes();
    $rfq->closePart(1, userWithRole('Business Development'));

    expect($rfq->refresh()->assigneeForPart(1)->pivot->bd_closed_at->equalTo($first))->toBeTrue()
        ->and($rfq->assigneeForPart(1)->pivot->bd_closed_by)->toBe($people['closer']->id);
});

it('counts each thing ready to close — a row on the page — and shows it as a red badge on the sidebar', function () {
    $people = closingPeople();

    // Three parts of one RFQ approved by the General Manager, one RFQ kept whole, and one at its closing stage with no part to show.
    $split = splitAmong(Rfq::factory()->create(), [1 => $people['sourcing'], 2 => $people['sourcing'], 3 => $people['sourcing']]);
    $whole = splitAmong(Rfq::factory()->create(), [1 => $people['sourcing']]);
    Rfq::factory()->create(['stage' => 'bd_closing']);
    foreach ([[$split, [1, 2, 3]], [$whole, [1]]] as [$rfq, $parts]) {
        foreach ($parts as $part) {
            $rfq->refresh()->completeSourcingPart($part);
            $rfq->refresh()->completeDataEntryPart($part, $people['dataEntry']);
            $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
            $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
            $rfq->refresh()->recordGmAssistantPart($part, $people['assistant'], 'Acme Ltd', null);
            $rfq->refresh()->approveGmPart($part, $people['gm']);
        }
    }

    // And one the General Manager hasn't got to yet, which isn't Business Development's.
    $notYet = splitAmong(Rfq::factory()->create(), [1 => $people['sourcing']]);
    foreach (['completeSourcingPart' => [1], 'completeDataEntryPart' => [1, $people['dataEntry']]] as $method => $args) {
        $notYet->refresh()->{$method}(...$args);
    }

    expect(Rfq::bdClosingCount())->toBe(5)
        ->and(Rfq::queueCounts()['closing'])->toBe(5);

    $badge = fn (int $count) => '<span class="nav-link-count" title="'.$count.' ready to close">'.$count.'</span>';
    $page = fn () => test()->actingAs($people['closer'])->get(closingUrl())->assertOk()->getContent();

    // Five rows on the page, five on the badge.
    $html = $page();
    expect(substr_count($html, 'data-confirm="Close '))->toBe(5)
        ->and($html)->toContain($badge(5));

    $split->refresh()->closePart(1, $people['closer']);

    expect($page())->toContain($badge(4));

    // Nothing left, no badge.
    foreach ([2, 3] as $part) {
        $split->refresh()->closePart($part, $people['closer']);
    }
    $whole->refresh()->closePart(1, $people['closer']);
    Rfq::where('stage', 'bd_closing')->update(['stage' => 'closed', 'status' => 'Completed']);

    expect($page())->not->toContain('ready to close">')
        ->and(Rfq::bdClosingCount())->toBe(0);
});

it('offers the dashboard\'s Ready to close list part by part, each with its own Close', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();
    $whole = Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'stage' => 'bd_closing', 'gm_approved_at' => now()->subDays(5)]);

    $dashboard = test()->actingAs($people['closer'])->get(route('admin.dashboard'))->assertOk();

    $dashboard->assertSee(route('admin.rfqs.close-part', $rfq))
        ->assertSee('data-confirm="Close RFQ1001-P1 of P2?', false)
        ->assertSee(route('admin.rfqs.close', $whole))
        ->assertViewHas('overview', function (array $overview) {
            // Longest-waiting first: the whole RFQ has waited longest.
            expect($overview['readyToCloseRfqs']->pluck('rfq_number')->all())->toBe(['RFQ2001', 'RFQ1001-P1 of P2'])
                ->and($overview['readyToClose'])->toBe(2);

            return true;
        });

    // Closing a part from the dashboard lands back on it.
    test()->actingAs($people['closer'])->from(route('admin.dashboard'))
        ->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1])
        ->assertRedirect(route('admin.dashboard'));

    expect($rfq->refresh()->assigneeForPart(1)->pivot->bd_closed_at)->not->toBeNull();
});

it('leaves a part Business Development has closed alone when the Head sends the RFQ back', function () {
    $people = closingPeople();
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001']), [1 => $people['sourcing'], 2 => $people['sourcing']]);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $people['dataEntry']);
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
    }
    // Part 1 all the way to closed; part 2 with the Head.
    $rfq->refresh()->approveHeadOfBdPart(1, $people['head']);
    $rfq->refresh()->recordGmAssistantPart(1, $people['assistant'], 'Acme Ltd', null);
    $rfq->refresh()->approveGmPart(1, $people['gm']);
    $rfq->refresh()->closePart(1, $people['closer']);

    expect($rfq->refresh()->stage)->toBe('head_of_bd_review');

    $rfq->rejectToStage('sourcing', 'Get more quotes', $people['head']);

    $rfq->refresh();
    $one = $rfq->assigneeForPart(1)->pivot;
    $two = $rfq->assigneeForPart(2)->pivot;

    // The closed part is as it was; the other is back with Sourcing.
    expect($one->bd_closed_at)->not->toBeNull()
        ->and($one->gm_approved_at)->not->toBeNull()
        ->and($one->completed_at)->not->toBeNull()
        ->and($one->returned_at)->toBeNull()
        ->and($two->completed_at)->toBeNull()
        ->and($two->returned_at)->not->toBeNull();
});

it('takes back a part\'s closing if it is sent back for rework', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();
    $rfq->closePart(1, $people['closer']);

    $rfq->refresh()->returnSourcingPart(1, 'Missing a quote', $people['dataEntry']);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->bd_closed_at)->toBeNull();
});

it('records each part\'s closing on a split\'s timeline, and none but the RFQ\'s own for one kept whole', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();
    $rfq->closePart(1, $people['closer']);

    test()->actingAs($people['gm'])->get(route('admin.rfqs.show', $rfq).'?status=Pending')
        ->assertOk()
        ->assertSee('Part closed by Business Development')
        ->assertSee('RFQ1001-P1 of P2');

    $whole = splitAmong(Rfq::factory()->create(), [1 => $people['sourcing']]);
    $whole->completeSourcingPart(1);
    $whole->refresh()->completeDataEntryPart(1, $people['dataEntry']);
    $whole->refresh()->approveSeniorOpsPart(1, $people['ops']);
    $whole->refresh()->approveHeadOfBdPart(1, $people['head']);
    $whole->refresh()->recordGmAssistantPart(1, $people['assistant'], 'Acme Ltd', null);
    $whole->refresh()->approveGmPart(1, $people['gm']);
    $whole->refresh()->closePart(1, $people['closer']);

    test()->actingAs($people['gm'])->get(route('admin.rfqs.show', $whole).'?status=Completed')
        ->assertOk()
        ->assertSee('RFQ Closed')
        ->assertDontSee('Part closed by Business Development');
});

it('lists each part\'s closing on a split\'s Closed tab, with a count while it is only partly done', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();
    $rfq->closePart(1, $people['closer']);

    $html = test()->actingAs($people['gm'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')->assertOk()->getContent();
    $pane = substr($html, strpos($html, 'id="step-pane-closed"'), 3000);

    expect($pane)->toContain('bg-success-subtle text-success-emphasis">'.e($people['closer']->name))
        ->toContain('RFQ1001-P2 of P2')
        // Part 2 hasn't been approved by the General Manager yet.
        ->toContain('bg-secondary-subtle text-secondary-emphasis">Not yet reached')
        ->and($html)->toContain('title="1 of 2 parts done">1/2</span>');
});

it('sends the parts of RFQs closed before this was part by part through along with them', function () {
    [$rfq, $people] = splitWithPartOneApprovedByGm();
    $rfq->approveGmPart(2, $people['gm']);
    $migration = require database_path('migrations/2026_09_20_062430_add_bd_closing_to_rfq_user_table.php');

    // Another, ready to close, with a part the General Manager has approved.
    $waiting = splitAmong(Rfq::factory()->create(), [1 => $people['sourcing']]);
    $waiting->completeSourcingPart(1);
    $waiting->refresh()->completeDataEntryPart(1, $people['dataEntry']);
    $waiting->refresh()->approveSeniorOpsPart(1, $people['ops']);
    $waiting->refresh()->approveHeadOfBdPart(1, $people['head']);
    $waiting->refresh()->recordGmAssistantPart(1, $people['assistant'], 'Acme Ltd', null);
    $waiting->refresh()->approveGmPart(1, $people['gm']);

    // As it was: the closing on the RFQ alone.
    $migration->down();
    DB::table('rfqs')->where('id', $rfq->id)->update(['bd_closed_at' => now()->subDay(), 'bd_closed_by' => $people['closer']->id, 'status' => 'Completed', 'stage' => 'closed']);

    $migration->up();

    $closed = DB::table('rfq_user')->where('rfq_id', $rfq->id)->get();
    $open = DB::table('rfq_user')->where('rfq_id', $waiting->id)->get();

    expect($closed->pluck('bd_closed_by')->all())->toBe([$people['closer']->id, $people['closer']->id])
        ->and($closed->every(fn ($part) => $part->bd_closed_at !== null))->toBeTrue()
        ->and($open->pluck('bd_closed_at')->all())->toBe([null]);
});
