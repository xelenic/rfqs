<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }

    // Wednesday 21 October, 11:00.
    test()->travelTo(CarbonImmutable::parse('2026-10-21 11:00', Setting::DEFAULT_TIMEZONE));
});

/**
 * $number, walked through to the General Manager's review — its one part
 * submitted by GM Assistant.
 */
function atGmFor(string $number, string $priority = 'Medium'): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'subject' => "Job {$number}", 'priority_level' => $priority]), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->completeSeniorOpsReview(userWithRole('Senior Operations'));
    $rfq->refresh()->approveByHeadOfBd(userWithRole('Head of Business Development'));
    $rfq->refresh()->recordGmAssistantDetails(userWithRole('GM Assistant'));

    return $rfq->refresh();
}

/**
 * The General Manager's RFQs: one waiting on them, one they approved and one
 * they sent back today, one they approved that's since been closed, one
 * they approved two months ago, one another General Manager approved, and
 * an Urgent one nowhere near them.
 *
 * @return array{gm: User}
 */
function gmsWeek(): array
{
    $gm = userWithRole('General Manager');
    $otherGm = userWithRole('General Manager');

    test()->travelTo(CarbonImmutable::parse('2026-08-19 11:00', Setting::DEFAULT_TIMEZONE));
    atGmFor('RFQ8005')->approveByGm($gm);
    test()->travelTo(CarbonImmutable::parse('2026-10-21 11:00', Setting::DEFAULT_TIMEZONE));

    atGmFor('RFQ8001');
    atGmFor('RFQ8002')->approveByGm($gm);
    atGmFor('RFQ8003')->rejectToStage('senior_ops_review', 'Check the totals', $gm, 'gm_review');
    $closed = atGmFor('RFQ8004');
    $closed->approveByGm($gm);
    $closed->refresh()->closeOut(userWithRole('Business Development'), 'PO-1');
    atGmFor('RFQ8006')->approveByGm($otherGm);
    Rfq::factory()->create(['rfq_number' => 'RFQ8007', 'priority_level' => 'Urgent']);

    return ['gm' => $gm];
}

/**
 * The RFQ numbers All RFQs lists for $gm with $query.
 *
 * @param  array<string, mixed>  $query
 * @return array<int, string>
 */
function gmListed(User $gm, array $query): array
{
    return test()->actingAs($gm)->get(route('admin.rfqs.index', $query))->assertOk()
        ->viewData('rfqs')->pluck('rfq_number')->sort()->values()->all();
}

it('leads each of the General Manager\'s cards to All RFQs, narrowed to what it counted over the period', function () {
    ['gm' => $gm] = gmsWeek();

    $response = test()->actingAs($gm)->get(route('admin.dashboard'))->assertOk();
    $period = $response->viewData('period');
    $dated = fn (string $show) => e(route('admin.rfqs.index', ['show' => $show, 'show_from' => $period['from'], 'show_to' => $period['to']]));
    $cards = Str::betweenFirst($response->getContent(), 'class="row g-3 mb-4"', 'class="row g-3"');

    expect($cards)->toContain('href="'.e(route('admin.rfqs.index', ['show' => 'awaiting_gm'])).'"')
        ->toContain('href="'.$dated('gm_approved').'"')
        ->toContain('href="'.$dated('gm_returned').'"')
        ->toContain('href="'.e(route('admin.rfqs.index', ['show' => 'open'])).'"')
        ->toContain('href="'.$dated('closed').'"')
        ->not->toContain('href="#gm-');
});

it('lists on each view the very RFQs its card counted', function (array $query, array $expected) {
    ['gm' => $gm] = gmsWeek();

    expect(gmListed($gm, $query + ['show_from' => '2026-10-19', 'show_to' => '2026-10-21']))->toBe($expected);
})->with([
    'awaiting your approval' => [['show' => 'awaiting_gm'], ['RFQ8001']],
    'approved by you, in the period — not before, nor by another' => [['show' => 'gm_approved'], ['RFQ8002', 'RFQ8004']],
    'sent back by you' => [['show' => 'gm_returned'], ['RFQ8003']],
    'open, whoever has them' => [['show' => 'open'], ['RFQ8001', 'RFQ8002', 'RFQ8003', 'RFQ8005', 'RFQ8006', 'RFQ8007']],
    'closed in the period' => [['show' => 'closed'], ['RFQ8004']],
    'open and Urgent — from Open RFQs by priority' => [['show' => 'open', 'priority' => ['Urgent']], ['RFQ8007']],
    'everything, without a view' => [[], ['RFQ8001', 'RFQ8002', 'RFQ8003', 'RFQ8004', 'RFQ8005', 'RFQ8006', 'RFQ8007']],
]);

it('says what\'s shown, over which dates, and how many — with the way back to every RFQ', function () {
    ['gm' => $gm] = gmsWeek();

    $page = test()->actingAs($gm)->get(route('admin.rfqs.index', ['show' => 'gm_approved', 'show_from' => '2026-10-19', 'show_to' => '2026-10-21']))->assertOk()
        ->assertSee('<title>All RFQs', false)
        ->assertSee('<option value="gm_approved" selected>Show: Approved by you</option>', false)
        ->assertSee('<input type="hidden" name="show_from" value="2026-10-19">', false)
        ->getContent();

    expect(Str::betweenFirst($page, 'data-all-rfqs-view="gm_approved"', '</div>'))
        ->toContain('Showing <strong>Approved by you</strong> from Oct 19, 2026 to Oct 21, 2026 — 2 RFQs.')
        ->toContain('href="'.route('admin.rfqs.index').'"');

    // Over all time, the one from August too.
    expect(gmListed($gm, ['show' => 'gm_approved']))->toBe(['RFQ8002', 'RFQ8004', 'RFQ8005']);
});

it('gives Admin the views too, as the General Manager\'s — and keeps the other approvers\' cards on their page', function () {
    gmsWeek();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.rfqs.index'))->assertOk()
        ->assertSee('Show: Approved by the General Manager');
    expect(gmListed($admin, ['show' => 'gm_approved', 'show_from' => '2026-10-19', 'show_to' => '2026-10-21']))->toBe(['RFQ8002', 'RFQ8004', 'RFQ8006']);

    // The Head of Business Development and GM Assistant have no All RFQs: their cards stay on their dashboard.
    foreach (['Head of Business Development' => 'hbd', 'GM Assistant' => 'gma'] as $role => $anchor) {
        $dashboard = test()->actingAs(userWithRole($role))->get(route('admin.dashboard'))->assertOk()->getContent();
        expect($dashboard)->toContain('href="#'.$anchor.'-waiting"')
            ->not->toContain('show=');
    }

    // Nor does ?show= do anything on their lists.
    expect(test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['show' => 'closed']))->assertOk()->getContent())
        ->not->toContain('data-all-rfqs-view');
});
