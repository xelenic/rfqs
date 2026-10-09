<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['Sourcing', 'Senior Operations', 'Data Entry', 'Head of Business Development', 'GM Assistant', 'General Manager', 'Business Development', 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * The people who take an RFQ through its approvals.
 *
 * @return array{ops: User, dataEntry: User, head: User, assistant: User, gm: User, closer: User, sourcing: User, admin: User}
 */
function progressPeople(): array
{
    return [
        'ops' => userWithRole('Senior Operations'),
        'dataEntry' => userWithRole('Data Entry'),
        'head' => userWithRole('Head of Business Development'),
        'assistant' => userWithRole('GM Assistant'),
        'gm' => userWithRole('General Manager'),
        'closer' => userWithRole('Business Development'),
        'sourcing' => userWithRole('Sourcing'),
        'admin' => userWithRole('Admin'),
    ];
}

/**
 * An RFQ split among $parts holders (or kept whole, with one), every part
 * through Sourcing and Data Entry.
 *
 * @param  array<int, User>  $holders
 */
function rfqThroughDataEntry(array $holders, User $dataEntry): Rfq
{
    // Assigned by Operations, as any RFQ that's got this far has been.
    $rfq = splitAmong(Rfq::factory()->create([
        'rfq_number' => 'RFQ1001',
        'operations_assigned_by' => userWithRole('Senior Operations')->id,
        'operations_assigned_at' => now(),
    ]), $holders);

    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry);
        $rfq->refresh()->finalizePart($part);
    }

    return $rfq->refresh();
}

/**
 * What the RFQ page hands its Progress chart: the state of each step down
 * every Sourcing branch (step => state), one entry per part in order.
 *
 * @return array<int, array<string, array{state: string, meta: array<int, string>}>>
 */
function progressBranches(Rfq $rfq, User $viewer): array
{
    $html = test()->actingAs($viewer)->get(route('admin.rfqs.show', $rfq).'?status=Pending')->assertOk()->getContent();

    preg_match('/<script type="application\/json" id="rfq-progress-data">(.*?)<\/script>/s', $html, $matches);

    $tree = json_decode($matches[1], true)['tree'];
    // RFQ Created → Assigned by Operations → Assigned to Sourcing → the
    // branches — Sourcing's own chart starting at Assigned by Operations.
    $operations = $tree['step'] === 'created' ? $tree['children'][0] : $tree;
    $branches = $operations['children'][0]['children'];

    return array_map(function (array $branch) {
        $steps = [];
        for ($node = $branch; $node !== null; $node = $node['children'][0] ?? null) {
            $steps[$node['step']] = ['state' => $node['state'], 'meta' => $node['meta']];
        }

        return $steps;
    }, $branches);
}

/**
 * @param  array<string, array{state: string, meta: array<int, string>}>  $branch
 * @return array<string, string>
 */
function tailStates(array $branch): array
{
    return collect($branch)->only(['senior_ops', 'head_of_bd', 'gm_assistant', 'gm_review', 'closed'])->map(fn (array $node) => $node['state'])->all();
}

it('shows each split part\'s own progress down its branch, each step going green as that part completes it', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing'], 3 => $people['sourcing']], $people['dataEntry']);

    // Part 1 all the way through the General Manager; part 2 approved by the Head and waiting on GM Assistant; part 3 waiting on Senior Operations.
    foreach ([1, 2] as $part) {
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
        $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
    }
    $rfq->refresh()->recordGmAssistantPart(1, $people['assistant']);
    $rfq->refresh()->approveGmPart(1, $people['gm']);

    [$one, $two, $three] = progressBranches($rfq, $people['admin']);

    expect(tailStates($one))->toBe(['senior_ops' => 'done', 'head_of_bd' => 'done', 'gm_assistant' => 'done', 'gm_review' => 'done', 'closed' => 'current'])
        ->and(tailStates($two))->toBe(['senior_ops' => 'done', 'head_of_bd' => 'done', 'gm_assistant' => 'current', 'gm_review' => 'pending', 'closed' => 'pending'])
        ->and(tailStates($three))->toBe(['senior_ops' => 'current', 'head_of_bd' => 'pending', 'gm_assistant' => 'pending', 'gm_review' => 'pending', 'closed' => 'pending']);

    // Each says who did it, or what it's waiting for.
    expect($one['senior_ops']['meta'][0])->toBe($people['ops']->name)
        ->and($one['head_of_bd']['meta'][0])->toBe('Approved by '.$people['head']->name)
        ->and($one['gm_assistant']['meta'][0])->toBe($people['assistant']->name)
        ->and($one['gm_review']['meta'][0])->toBe('Approved by '.$people['gm']->name)
        // Approved by the General Manager: ready for Business Development to close, whatever the others are up to.
        ->and($one['closed']['meta'])->toBe(['Ready for Business Development'])
        ->and($two['gm_assistant']['meta'])->toBe(['Awaiting GM Assistant'])
        ->and($two['closed']['meta'])->toBe(['Not yet reached'])
        ->and($three['senior_ops']['meta'])->toBe(['Awaiting review'])
        ->and($three['head_of_bd']['meta'])->toBe(['Not yet reached']);
});

it('goes green straight away when Senior Operations approves a part, ahead of the rest', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing']], $people['dataEntry']);

    [$one, $two] = progressBranches($rfq, $people['admin']);

    expect($one['senior_ops']['state'])->toBe('current')
        ->and($two['senior_ops']['state'])->toBe('current');

    $rfq->approveSeniorOpsPart(1, $people['ops']);

    [$one, $two] = progressBranches($rfq->refresh(), $people['admin']);

    expect($one['senior_ops']['state'])->toBe('done')
        ->and($one['head_of_bd']['state'])->toBe('current')
        ->and($two['senior_ops']['state'])->toBe('current')
        ->and($two['head_of_bd']['state'])->toBe('pending');
});

it('keeps a part that is still with Sourcing or Data Entry pending all the way down', function () {
    $people = progressPeople();
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $people['sourcing'], 2 => $people['sourcing'], 3 => $people['sourcing']]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(1, $people['dataEntry']);
    $rfq->refresh()->finalizePart(1);

    [$one, $two, $three] = progressBranches($rfq->refresh(), $people['admin']);

    expect(tailStates($one))->toHaveKey('senior_ops', 'current')
        ->and(tailStates($two))->toBe(['senior_ops' => 'pending', 'head_of_bd' => 'pending', 'gm_assistant' => 'pending', 'gm_review' => 'pending', 'closed' => 'pending'])
        ->and(tailStates($three))->toBe(['senior_ops' => 'pending', 'head_of_bd' => 'pending', 'gm_assistant' => 'pending', 'gm_review' => 'pending', 'closed' => 'pending']);
});

it('takes an RFQ kept whole green step by step, through to Closed', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing']], $people['dataEntry']);
    $tail = fn () => tailStates(progressBranches($rfq->refresh(), $people['admin'])[0]);

    expect($tail())->toBe(['senior_ops' => 'current', 'head_of_bd' => 'pending', 'gm_assistant' => 'pending', 'gm_review' => 'pending', 'closed' => 'pending']);

    $rfq->approveSeniorOpsPart(1, $people['ops']);
    expect($tail())->toBe(['senior_ops' => 'done', 'head_of_bd' => 'current', 'gm_assistant' => 'pending', 'gm_review' => 'pending', 'closed' => 'pending']);

    $rfq->refresh()->approveHeadOfBdPart(1, $people['head']);
    expect($tail())->toBe(['senior_ops' => 'done', 'head_of_bd' => 'done', 'gm_assistant' => 'current', 'gm_review' => 'pending', 'closed' => 'pending']);

    $rfq->refresh()->recordGmAssistantPart(1, $people['assistant']);
    expect($tail())->toBe(['senior_ops' => 'done', 'head_of_bd' => 'done', 'gm_assistant' => 'done', 'gm_review' => 'current', 'closed' => 'pending']);

    $rfq->refresh()->approveGmPart(1, $people['gm']);
    expect($tail())->toBe(['senior_ops' => 'done', 'head_of_bd' => 'done', 'gm_assistant' => 'done', 'gm_review' => 'done', 'closed' => 'current'])
        ->and(progressBranches($rfq->refresh(), $people['admin'])[0]['closed']['meta'])->toBe(['Ready for Business Development']);

    $rfq->refresh()->closeOut($people['closer']);

    expect($tail())->toBe(['senior_ops' => 'done', 'head_of_bd' => 'done', 'gm_assistant' => 'done', 'gm_review' => 'done', 'closed' => 'done'])
        ->and(progressBranches($rfq->refresh(), $people['admin'])[0]['closed']['meta'][0])->toBe($people['closer']->name);
});

it('closes each branch as Business Development closes its part, and the RFQ once every part is', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing']], $people['dataEntry']);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
        $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
        $rfq->refresh()->recordGmAssistantPart($part, $people['assistant']);
        $rfq->refresh()->approveGmPart($part, $people['gm']);
    }

    // Every part is approved: Closed is up next on both.
    foreach (progressBranches($rfq->refresh(), $people['admin']) as $branch) {
        expect($branch['closed']['state'])->toBe('current')
            ->and($branch['closed']['meta'])->toBe(['Ready for Business Development']);
    }

    // One part closed: that branch is green with who closed it, the other still to do.
    $rfq->closePart(1, $people['closer']);

    [$one, $two] = progressBranches($rfq->refresh(), $people['admin']);

    expect($one['closed']['state'])->toBe('done')
        ->and($one['closed']['meta'][0])->toBe($people['closer']->name)
        ->and($two['closed']['state'])->toBe('current');

    // The last one: the RFQ is closed, and every branch is green.
    $rfq->refresh()->closePart(2, $people['closer']);

    foreach (progressBranches($rfq->refresh(), $people['admin']) as $branch) {
        expect(tailStates($branch))->toBe(['senior_ops' => 'done', 'head_of_bd' => 'done', 'gm_assistant' => 'done', 'gm_review' => 'done', 'closed' => 'done']);
    }
});

it('shows a part the Head sent back as returned on its own branch, and the whole RFQ sent back on every one', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing']], $people['dataEntry']);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
    }

    // One part.
    $rfq->refresh()->rejectPartToStage(2, 'data_entry', 'Prices mistyped', $people['head']);

    [$one, $two] = progressBranches($rfq->refresh(), $people['admin']);

    expect($one['head_of_bd']['state'])->toBe('current')
        ->and($two['head_of_bd']['state'])->toBe('returned')
        ->and($two['head_of_bd']['meta'])->toBe(['Rejected by '.$people['head']->name, 'Returned to Data Entry'])
        // And it's back at Data Entry: nothing beyond it.
        ->and($two['data_entry']['state'])->toBe('pending')
        ->and($two['senior_ops']['state'])->toBe('pending');

    // The whole RFQ, from where it's with the Head.
    $rfq->refresh()->completeDataEntryPart(2, $people['dataEntry']);
    $rfq->refresh()->finalizePart(2);
    $rfq->refresh()->approveSeniorOpsPart(2, $people['ops']);
    $rfq->refresh()->rejectToStage('senior_ops_review', 'All of it, again', $people['head']);

    foreach (progressBranches($rfq->refresh(), $people['admin']) as $branch) {
        expect($branch['head_of_bd']['state'])->toBe('returned')
            ->and($branch['head_of_bd']['meta'])->toBe(['Rejected by '.$people['head']->name, 'Returned to Senior Operations (2nd review)'])
            // Both are back with Senior Operations, each up for review again.
            ->and($branch['senior_ops']['state'])->toBe('current');
    }

    // Approved again, it's no longer shown as sent back.
    $rfq->refresh()->approveSeniorOpsPart(1, $people['ops']);
    $rfq->refresh()->approveHeadOfBdPart(1, $people['head']);

    expect(progressBranches($rfq->refresh(), $people['admin'])[0]['head_of_bd']['state'])->toBe('done');
});

it('still masks who approved from Sourcing, on each branch, as far as their chart goes', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing']], $people['dataEntry']);
    $rfq->approveSeniorOpsPart(1, $people['ops']);
    $rfq->refresh()->approveHeadOfBdPart(1, $people['head']);

    [$one] = progressBranches($rfq->refresh(), $people['sourcing']);

    expect($one['senior_ops']['state'])->toBe('done')
        ->and($one['senior_ops']['meta'][0])->toBe('Restricted')
        // Senior Operations' review is as far as Sourcing's chart goes.
        ->and($one)->not->toHaveKey('head_of_bd');
});

it('shows Sourcing only the Operations, Sourcing, Data Entry and Senior Operations boxes and tabs', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing']], $people['dataEntry']);

    $chartSteps = function (User $viewer) use ($rfq) {
        $html = test()->actingAs($viewer)->get(route('admin.rfqs.show', $rfq).'?status=Pending')->assertOk()->getContent();
        preg_match('/<script type="application\/json" id="rfq-progress-data">(.*?)<\/script>/s', $html, $matches);

        $steps = [];
        $walk = function (array $node) use (&$walk, &$steps) {
            $steps[] = $node['step'];
            foreach ($node['children'] as $child) {
                $walk($child);
            }
        };
        $walk(json_decode($matches[1], true)['tree']);
        preg_match_all('/id="step-tab-([a-z_]+)"/', $html, $tabs);
        preg_match_all('/id="step-pane-([a-z_]+)"/', $html, $panes);

        return ['steps' => array_values(array_unique($steps)), 'tabs' => $tabs[1], 'panes' => $panes[1]];
    };

    $sourcing = $chartSteps($people['sourcing']);
    $mine = ['operations', 'sourcing', 'data_entry', 'finalize', 'senior_ops'];

    expect($sourcing['steps'])->toBe($mine)
        ->and($sourcing['tabs'])->toBe($mine)
        ->and($sourcing['panes'])->toBe($mine);

    // Senior Operations — like everyone without a view of their own — sees the whole of it.
    expect($chartSteps($people['ops'])['steps'])
        ->toBe(['created', 'operations', 'sourcing', 'data_entry', 'finalize', 'senior_ops', 'head_of_bd', 'gm_assistant', 'gm_review', 'closed']);
});

it('counts the parts through each stage on its tab while the stage as a whole isn\'t done', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing'], 3 => $people['sourcing']], $people['dataEntry']);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
    }
    $rfq->refresh()->approveHeadOfBdPart(1, $people['head']);

    $tabs = fn () => test()->actingAs($people['admin'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')->assertOk()->getContent();

    expect($tabs())
        ->toContain('title="2 of 3 parts done">2/3</span>')
        ->toContain('title="1 of 3 parts done">1/3</span>')
        // Sourcing and Data Entry are done for all three: a tick, not a count.
        ->not->toContain('3/3');

    $rfq->approveSeniorOpsPart(3, $people['ops']);

    // Senior Operations is done for the RFQ now, so its tab has the tick and no count.
    expect($tabs())->not->toContain('2/3</span>');
});

it('lists each part\'s step on a split\'s tab, with who did it and what the others are waiting for', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing'], 3 => $people['sourcing']], $people['dataEntry']);
    $rfq->approveSeniorOpsPart(1, $people['ops']);
    $rfq->refresh()->approveSeniorOpsPart(2, $people['ops']);
    $rfq->refresh()->approveHeadOfBdPart(1, $people['head']);
    $rfq->refresh()->recordGmAssistantPart(1, $people['assistant']);

    $page = fn () => test()->actingAs($people['admin'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')->assertOk()->getContent();
    $html = $page();
    $pane = fn (string $step, ?string $from = null) => substr($from ?? $html, strpos($from ?? $html, 'id="step-pane-'.$step.'"'), 4000);

    // Senior Operations: parts 1 and 2 approved, part 3 waiting.
    expect($pane('senior_ops'))
        ->toContain('RFQ1001-P1 of P3')
        ->toContain('bg-success-subtle text-success-emphasis">'.e($people['ops']->name))
        ->toContain('bg-primary-subtle text-primary-emphasis">Awaiting review');

    // The Head: part 1 approved, part 2 waiting, part 3 not there yet.
    expect($pane('head_of_bd'))
        ->toContain('bg-success-subtle text-success-emphasis">'.e($people['head']->name))
        ->toContain('bg-primary-subtle text-primary-emphasis">Awaiting review')
        ->toContain('bg-secondary-subtle text-secondary-emphasis">Not yet reached');

    // GM Assistant: part 1 submitted — no client details or payment terms any more.
    expect($pane('gm_assistant'))
        ->toContain(e($people['assistant']->name))
        ->not->toContain('Client Details');

    // An older RFQ still shows what was given then.
    $rfq->update(['client_details' => 'Acme Ltd, Colombo', 'payment_terms' => 'Net 30']);
    expect($pane('gm_assistant', $page()))
        ->toContain('Client Details')
        ->toContain('Acme Ltd, Colombo')
        ->toContain('Payment Terms')
        ->toContain('Net 30');
});

it('says which part was sent back on the Head\'s tab', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing']], $people['dataEntry']);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
    }
    $rfq->refresh()->rejectPartToStage(2, 'sourcing', 'Get another quote', $people['head']);

    $html = test()->actingAs($people['admin'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')->assertOk()->getContent();
    $pane = substr($html, strpos($html, 'id="step-pane-head_of_bd"'), 4000);

    expect($pane)->toContain('<dt>Rejected by</dt>')
        ->toContain('<dt>Part</dt>')
        ->toContain('RFQ1001-P2 of P2')
        ->toContain('Get another quote');
});

it('keeps an RFQ kept whole\'s tabs as they were', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing']], $people['dataEntry']);
    $rfq->approveSeniorOpsPart(1, $people['ops']);

    $html = test()->actingAs($people['admin'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')->assertOk()->getContent();
    $pane = fn (string $step) => substr($html, strpos($html, 'id="step-pane-'.$step.'"'), 1500);

    expect($pane('senior_ops'))->toContain('<dt>Approved by</dt>')->toContain(e($people['ops']->name))->not->toContain('<th>Assignee</th>')
        ->and($pane('head_of_bd'))->toContain('Awaiting review.')
        ->and($pane('gm_assistant'))->toContain('Not yet reached.')
        // No part counts on an RFQ that isn't split.
        ->and($html)->not->toContain('parts done">');
});

it('shows Business Development only Senior Operations\' boxes — the assignment, then each part\'s review', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing']], $people['dataEntry']);
    $rfq->approveSeniorOpsPart(1, $people['ops']);

    $html = test()->actingAs($people['closer'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')->assertOk()->getContent();
    preg_match('/<script type="application\/json" id="rfq-progress-data">(.*?)<\/script>/s', $html, $matches);
    $tree = json_decode($matches[1], true)['tree'];

    expect($tree['step'])->toBe('operations')
        ->and(array_column($tree['children'], 'step'))->toBe(['senior_ops', 'senior_ops'])
        ->and(array_column($tree['children'], 'rfq_number'))->toBe(['RFQ1001-P1 of P2', 'RFQ1001-P2 of P2'])
        ->and(array_column($tree['children'], 'state'))->toBe(['done', 'current'])
        ->and(array_merge(...array_column($tree['children'], 'children')))->toBe([]);

    preg_match_all('/id="step-tab-([a-z_]+)"/', $html, $tabs);
    preg_match_all('/id="step-pane-([a-z_]+)"/', $html, $panes);

    expect($tabs[1])->toBe(['operations', 'senior_ops'])
        ->and($panes[1])->toBe(['operations', 'senior_ops'])
        // Past Senior Operations' assignment, it opens on their review.
        ->and($html)->toContain('class="tab-pane fade show active" id="step-pane-senior_ops"');
});

it('shows Data Entry only the Sourcing and Data Entry boxes and tabs', function () {
    $people = progressPeople();
    $rfq = rfqThroughDataEntry([1 => $people['sourcing'], 2 => $people['sourcing']], $people['dataEntry']);
    $rfq->approveSeniorOpsPart(1, $people['ops']);

    $html = test()->actingAs($people['dataEntry'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')->assertOk()->getContent();
    preg_match('/<script type="application\/json" id="rfq-progress-data">(.*?)<\/script>/s', $html, $matches);
    $tree = json_decode($matches[1], true)['tree'];

    // Assigned to Sourcing → each part's Sourcing → its Data Entry, and no further.
    expect($tree['name'])->toBe('Assigned to Sourcing')
        ->and(array_column($tree['children'], 'step'))->toBe(['sourcing', 'sourcing'])
        ->and(array_map(fn (array $branch) => array_column($branch['children'], 'step'), $tree['children']))->toBe([['data_entry'], ['data_entry']])
        ->and(array_merge(...array_map(fn (array $branch) => $branch['children'][0]['children'], $tree['children'])))->toBe([]);

    preg_match_all('/id="step-tab-([a-z_]+)"/', $html, $tabs);
    preg_match_all('/id="step-pane-([a-z_]+)"/', $html, $panes);

    expect($tabs[1])->toBe(['sourcing', 'data_entry'])
        ->and($panes[1])->toBe(['sourcing', 'data_entry'])
        // Past their step, it opens on the latest of theirs: Data Entry.
        ->and($html)->toContain('class="tab-pane fade show active" id="step-pane-data_entry"');
});
