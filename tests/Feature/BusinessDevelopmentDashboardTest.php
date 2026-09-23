<?php

use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

function businessDevelopmentDashboard(): TestResponse
{
    return test()->actingAs(userWithRole('Business Development'))->get(route('admin.dashboard'))->assertOk();
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

it('keeps the general dashboard for everyone else, Admin included', function () {
    foreach (['Senior Operations', 'Data Entry', 'Head of Business Development', 'Admin'] as $role) {
        test()->actingAs(userWithRole($role))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard')
            ->assertSee('Permissions');
    }

    // Holding Business Development too doesn't take Admin's dashboard away.
    test()->actingAs(userWithRole('Admin')->assignRole('Business Development'))
        ->get(route('admin.dashboard'))
        ->assertViewIs('admin.dashboard');
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
    Rfq::factory()->create([
        'created_at' => $yesterday, 'gm_approved_at' => $yesterday,
        'rejected_at' => $yesterday, 'bd_closed_at' => $yesterday,
        'status' => 'Completed', 'stage' => 'closed',
    ]);

    // Today's.
    Rfq::factory()->count(2)->create();
    Rfq::factory()->create(['created_at' => $yesterday, 'gm_approved_at' => now()->subHour(), 'stage' => 'bd_closing']);
    Rfq::factory()->create(['created_at' => $yesterday, 'rejected_at' => now()->subHour()]);
    Rfq::factory()->create(['created_at' => $yesterday, 'bd_closed_at' => now()->subHours(2), 'status' => 'Completed', 'stage' => 'closed']);

    businessDevelopmentDashboard()->assertViewHas('overview', function (array $overview) {
        expect($overview['today'])->toBe(['created' => 2, 'approved' => 1, 'sentBack' => 1, 'closed' => 1]);

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
        ->patch(route('admin.rfqs.close', $waiting))
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
