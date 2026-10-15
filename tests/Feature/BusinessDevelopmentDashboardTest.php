<?php

use App\Models\Rfq;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // Midday, so "earlier today" and "yesterday" mean the same at any hour.
    test()->travelTo(now()->setTime(12, 0));

    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Business Development's dashboard, with $query in its address — the
 * summary's period, say.
 *
 * @param  array<string, mixed>  $query
 */
function businessDevelopmentDashboard(array $query = []): TestResponse
{
    return test()->actingAs(userWithRole('Business Development'))->get(route('admin.dashboard', $query))->assertOk();
}

/**
 * $rfq sent back to $stageLabel at $at — the comment every send-back
 * leaves (Rfq::rejectToStage()), which the summary counts.
 */
function bdSummaryReturn(Rfq $rfq, string $stageLabel, CarbonInterface $at): void
{
    $rfq->comments()->forceCreate([
        'user_id' => null,
        'action' => 'rejected',
        'body' => 'Needs another look.',
        'meta' => ['stage' => $stageLabel],
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/**
 * The summary Business Development's dashboard shows for $query.
 *
 * @param  array<string, mixed>  $query
 * @return array{period: array<string, mixed>, summary: array<string, int>}
 */
function bdSummaryFor(array $query = []): array
{
    $view = businessDevelopmentDashboard($query)->viewData('overview');

    return ['period' => businessDevelopmentDashboard($query)->viewData('period'), 'summary' => $view['summary']];
}

it('gives Business Development a dashboard of its own', function () {
    businessDevelopmentDashboard()
        ->assertViewIs('admin.dashboard.business-development')
        ->assertSee('Pending RFQs')
        ->assertSee('In Review')
        ->assertSee('Ready to Close')
        ->assertSee('Closed RFQs')
        ->assertSee('Today\'s summary', false)
        ->assertSee('Pending RFQs by stage')
        // The chart, with the finished series called Closed rather than Completed.
        ->assertSee('id="rfqActivityChart"', false)
        ->assertSee('<th>Closed</th>', false)
        // Nothing about users, roles or permissions — that's not their business.
        ->assertDontSee('Permissions');
});

it('keeps the general dashboard for everyone else, and Admin\'s own for Admin', function () {
    foreach (['Senior Operations', 'General Manager', 'Head of Business Development'] as $role) {
        test()->actingAs(userWithRole($role))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard')
            ->assertSee('Permissions');
    }

    // Holding Business Development too doesn't take Admin's dashboard away.
    test()->actingAs(userWithRole('Admin')->assignRole('Business Development'))
        ->get(route('admin.dashboard'))
        ->assertViewIs('admin.dashboard.admin');
});

it('counts the queues and where the pending RFQs sit', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];

    // The factory picks a priority at random, and only one should be Urgent.
    $rfq = fn (array $attributes = []) => Rfq::factory()->create($attributes + ['priority_level' => 'Medium']);

    $rfq(['priority_level' => 'Urgent']);                       // awaiting Sourcing
    splitAmong($rfq(), [1 => $riley]);                          // with Sourcing
    splitAmong($rfq(), [1 => $sam])->completeSourcingPart(1);   // with Data Entry
    foreach (['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review', 'bd_closing'] as $stage) {
        $rfq(['stage' => $stage]);
    }
    $rfq(['status' => 'Completed', 'stage' => 'closed']);

    businessDevelopmentDashboard()->assertViewHas('overview', function (array $overview) {
        expect($overview)->toMatchArray([
            'pending' => 8,
            'urgent' => 1,
            'inReview' => 4,
            'readyToClose' => 1,
            'closed' => 1,
        ])->and(collect($overview['stages'])->pluck('count', 'label')->all())->toBe([
            'Awaiting Sourcing' => 1,
            'With Sourcing' => 1,
            'With Data Entry' => 1,
            'Senior Operations review' => 1,
            'Head of Business Development' => 1,
            'GM Assistant' => 1,
            'General Manager' => 1,
            'Ready to close' => 1,
        ]);

        return true;
    });
});

it('summarises today only', function () {
    $yesterday = now()->subDay();

    // Yesterday's — none of it counts.
    bdSummaryReturn(Rfq::factory()->create([
        'created_at' => $yesterday, 'gm_approved_at' => $yesterday, 'bd_closed_at' => $yesterday,
        'status' => 'Completed', 'stage' => 'closed',
    ]), 'Data Entry', $yesterday);

    // Today's.
    Rfq::factory()->count(2)->create();
    Rfq::factory()->create(['created_at' => $yesterday, 'gm_approved_at' => now()->subHour(), 'stage' => 'bd_closing']);
    bdSummaryReturn(Rfq::factory()->create(['created_at' => $yesterday]), 'Data Entry', now()->subHour());
    Rfq::factory()->create(['created_at' => $yesterday, 'bd_closed_at' => now()->subHours(2), 'status' => 'Completed', 'stage' => 'closed']);

    businessDevelopmentDashboard()->assertViewHas('overview', function (array $overview) {
        expect($overview['summary'])->toBe(['created' => 2, 'approved' => 1, 'returned' => 1, 'returnedToBd' => 0, 'closed' => 1])
            ->and($overview['closedToday'])->toBe(1);

        return true;
    });
});

it('lists what happened today, latest first, with who did it', function () {
    $gm = userWithRole('General Manager');
    $creator = userWithRole('Business Development');

    Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'created_by' => $creator->id, 'created_at' => now()->subHours(3)]);
    Rfq::factory()->create([
        'rfq_number' => 'RFQ1002', 'created_at' => now()->subDay(), 'stage' => 'bd_closing',
        'gm_approved_at' => now()->subMinutes(30), 'gm_approved_by' => $gm->id,
    ]);
    Rfq::factory()->create(['rfq_number' => 'RFQ1003', 'created_at' => now()->subDays(3), 'senior_ops_reviewed_at' => now()->subDay()]);

    businessDevelopmentDashboard()
        ->assertSee('approved by the General Manager — ready to close')
        ->assertSee($gm->name)
        ->assertSee($creator->name)
        // Yesterday's review isn't today's activity.
        ->assertDontSee('passed Senior Operations review')
        ->assertViewHas('overview', function (array $overview) {
            expect($overview['feed']->pluck('rfq.rfq_number')->all())->toBe(['RFQ1002', 'RFQ1001']);

            return true;
        });
});

// ---- the summary's period --------------------------------------------------

it('counts a day as the working hours\' time zone has it, not UTC', function () {
    // Midday UTC is 5:30 PM in Colombo: 8 PM UTC yesterday was already
    // 1:30 AM today there, while 6 PM UTC yesterday was 11:30 PM yesterday.
    Rfq::factory()->create(['created_at' => now()->subDay()->setTime(20, 0)]);
    Rfq::factory()->create(['created_at' => now()->subDay()->setTime(18, 0)]);

    expect(bdSummaryFor()['summary']['created'])->toBe(1);
});

it('covers today, the last 3 days or the week, today included', function () {
    foreach ([0, 2, 3, 6, 7] as $daysAgo) {
        Rfq::factory()->create(['created_at' => now()->subDays($daysAgo)]);
    }

    expect(bdSummaryFor()['summary']['created'])->toBe(1)
        ->and(bdSummaryFor(['period' => 'today'])['summary']['created'])->toBe(1)
        ->and(bdSummaryFor(['period' => '3d'])['summary']['created'])->toBe(2)
        ->and(bdSummaryFor(['period' => 'week'])['summary']['created'])->toBe(4);

    expect(bdSummaryFor(['period' => '3d'])['period'])->toMatchArray([
        'key' => '3d',
        'from' => now()->subDays(2)->toDateString(),
        'to' => now()->toDateString(),
        'isToday' => false,
    ]);
});

it('covers a custom range — either way round, from a date to today, or a single day', function () {
    foreach (['2026-09-01', '2026-09-15', '2026-09-30', '2026-10-05'] as $day) {
        Rfq::factory()->create(['created_at' => Carbon::parse("{$day} 06:00")]);
    }
    test()->travelTo(Carbon::parse('2026-10-10 12:00'));

    expect(bdSummaryFor(['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'])['summary']['created'])->toBe(3)
        ->and(bdSummaryFor(['period' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-01'])['period'])->toMatchArray(['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and(bdSummaryFor(['period' => 'custom', 'from' => '2026-09-15'])['summary']['created'])->toBe(3)
        ->and(bdSummaryFor(['period' => 'custom', 'from' => '2026-09-15'])['period'])->toMatchArray(['from' => '2026-09-15', 'to' => '2026-10-10'])
        ->and(bdSummaryFor(['period' => 'custom', 'to' => '2026-09-15'])['summary']['created'])->toBe(1);
});

it('falls back to today for a period it doesn\'t know, or a custom one with no dates', function (array $query) {
    expect(bdSummaryFor($query)['period'])->toMatchArray(['key' => 'today', 'isToday' => true]);
})->with([
    'unknown' => [['period' => 'year']],
    'custom, no dates' => [['period' => 'custom']],
    'custom, not dates' => [['period' => 'custom', 'from' => 'last tuesday', 'to' => '10/01/2026']],
    'dates, not custom' => [['from' => '2026-09-01', 'to' => '2026-09-30']],
]);

it('counts each RFQ returned once, says how many came back to Business Development, and keeps them counted once fixed', function () {
    $ops = userWithRole('Senior Operations');

    // Returned to Business Development, and already fixed.
    $fixed = Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'stage' => 'senior_ops_review']);
    $fixed->rejectToStage('business_development', 'Which building?', $ops, 'senior_ops_review');
    $fixed->resolveBusinessDevelopmentReturn();
    expect($fixed->refresh()->rejected_at)->toBeNull();

    // Returned twice, elsewhere.
    $twice = Rfq::factory()->create(['rfq_number' => 'RFQ2002']);
    bdSummaryReturn($twice, 'Data Entry', now()->subHours(3));
    bdSummaryReturn($twice, 'Sourcing', now()->subHours(2));

    // Last week's doesn't count today — but does for the week.
    bdSummaryReturn(Rfq::factory()->create(), 'Business Development', now()->subDays(5));

    expect(bdSummaryFor()['summary'])->toMatchArray(['returned' => 2, 'returnedToBd' => 1])
        ->and(bdSummaryFor(['period' => 'week'])['summary'])->toMatchArray(['returned' => 3, 'returnedToBd' => 2]);

    businessDevelopmentDashboard()
        ->assertSee('1 to Business Development')
        ->assertSeeInOrder(['RFQ2001', 'returned to Business Development', $ops->name, 'RFQ2002', 'returned to Sourcing'])
        ->assertSee('returned to Data Entry');
});

it('shows the period picked, with dates on the activity once it\'s more than a day', function () {
    Rfq::factory()->create(['rfq_number' => 'RFQ3001', 'created_at' => now()->subDays(2)->setTime(6, 0)]);

    $when = now()->subDays(2)->setTime(6, 0)->setTimezone(Setting::timezone());

    businessDevelopmentDashboard(['period' => '3d'])
        ->assertDontSee('Today\'s summary', false)
        ->assertSeeInOrder(['<span>', 'Summary', '</span>', '<h3 class="today-heading">', 'Activity', '</h3>'], false)
        ->assertSee('value="3d" checked', false)
        ->assertSee('id="summaryCustomRange"', false)
        ->assertSee($when->format('M j, g:i A'))
        // The chart's own range keeps the period.
        ->assertSee('period=3d&amp;range=30', false);

    businessDevelopmentDashboard(['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'])
        ->assertSee('value="custom" checked', false)
        ->assertSee('value="2026-01-01"', false)
        ->assertSee('Nothing happened in this period.');

    businessDevelopmentDashboard()
        ->assertSee('Today\'s summary', false)
        ->assertDontSee('@else', false)
        ->assertSee('value="today" checked', false)
        ->assertSee('class="ops-custom-range js-custom-range d-none" id="summaryCustomRange"', false);
});

it('says so when nothing has happened today', function () {
    businessDevelopmentDashboard()->assertSee('Nothing has happened yet today.');
});

it('offers the RFQs ready to close, longest-waiting first, with a Close button', function () {
    $waiting = Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'stage' => 'bd_closing', 'gm_approved_at' => now()->subDays(2)]);
    Rfq::factory()->create(['rfq_number' => 'RFQ1002', 'stage' => 'bd_closing', 'gm_approved_at' => now()->subHour()]);
    Rfq::factory()->create(['rfq_number' => 'RFQ1003', 'stage' => 'gm_review']);

    businessDevelopmentDashboard()
        ->assertSee(route('admin.rfqs.close', $waiting))
        ->assertViewHas('overview', function (array $overview) {
            expect($overview['readyToCloseRfqs']->pluck('rfq_number')->all())->toBe(['RFQ1001', 'RFQ1002']);

            return true;
        });

    // Closing from the dashboard works, and lands back on it.
    test()->actingAs(userWithRole('Business Development'))
        ->from(route('admin.dashboard'))
        ->patch(route('admin.rfqs.close', $waiting), ['reference_code' => 'PO-1001'])
        ->assertRedirect(route('admin.dashboard'));

    expect($waiting->refresh()->status)->toBe('Completed');
});

it('leads Business Development\'s notifications with what\'s ready to close', function () {
    Rfq::factory()->count(2)->create(['stage' => 'bd_closing']);

    businessDevelopmentDashboard()->assertSee('2 RFQs ready to close');

    // Not something the other roles are told about.
    test()->actingAs(userWithRole('Sourcing'))
        ->get(route('admin.dashboard'))
        ->assertDontSee('ready to close');
});
