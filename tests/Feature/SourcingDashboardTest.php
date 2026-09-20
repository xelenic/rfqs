<?php

use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
 * An open RFQ with a subject to spot it by (the factory picks a priority at
 * random, so it's pinned).
 *
 * @param  array<string, mixed>  $attributes
 */
function sourcingRfq(string $subject, array $attributes = []): Rfq
{
    return Rfq::factory()->create($attributes + ['subject' => $subject, 'priority_level' => 'Medium']);
}

it('gives Sourcing a dashboard of its own', function () {
    test()->actingAs(userWithRole('Sourcing'))
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertViewIs('admin.dashboard.sourcing')
        ->assertSee('Assigned to You')
        ->assertSee('Pending')
        ->assertSee('In Review')
        ->assertSee('Returned')
        ->assertSee('Recently assigned')
        ->assertSee('Review items')
        ->assertSee('Your workload')
        // Nothing about users, roles or permissions, or the company's other RFQs.
        ->assertDontSee('Permissions')
        ->assertDontSee('Recent RFQs');
});

it('leaves Admin and the other roles with the general dashboard', function () {
    foreach (['Admin', 'Senior Operations', 'Data Entry', 'General Manager'] as $role) {
        test()->actingAs(userWithRole($role))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard');
    }

    // An Admin who also holds Sourcing keeps Admin's.
    test()->actingAs(userWithRole('Admin')->assignRole('Sourcing'))
        ->get(route('admin.dashboard'))
        ->assertViewIs('admin.dashboard');
});

it('counts only their own parts, by where each stands', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $dataEntry = userWithRole('Data Entry');

    // Riley: P1 done by Data Entry, P2 with Data Entry, P3 returned, P4 in progress (on an Urgent RFQ).
    $split = splitAmong(sourcingRfq('Split job'), [1 => $riley, 2 => $riley, 3 => $riley, 4 => $sam]);
    $split->completeSourcingPart(1);
    $split->refresh()->completeDataEntryPart(1, $dataEntry);
    $split->refresh()->completeSourcingPart(2);
    $split->refresh()->completeSourcingPart(3);
    $split->refresh()->returnSourcingPart(3, 'Prices missing', $dataEntry);

    splitAmong(sourcingRfq('Urgent job', ['priority_level' => 'Urgent']), [1 => $riley]);

    // Sam's own, and someone else's RFQ altogether, don't count for Riley.
    splitAmong(sourcingRfq('Sam only'), [1 => $sam]);
    // An RFQ that's closed no longer counts.
    splitAmong(sourcingRfq('Closed job', ['status' => 'Completed']), [1 => $riley]);

    test()->actingAs($riley)->get(route('admin.dashboard'))->assertViewHas('overview', function (array $overview) {
        expect($overview)->toMatchArray([
            // P1–P3 of the split and the Urgent one; not Sam's P4, and not the closed RFQ.
            'assigned' => 4,
            // Still theirs to complete: the returned P3, the in-progress Urgent one — and not what's already handed on.
            'pending' => 2,
            'urgent' => 1,
            'returned' => 1,
            'inReview' => 1,
            'done' => 1,
            'inProgress' => 1,
        ]);

        return true;
    });

    // Sam sees just his two.
    test()->actingAs($sam)->get(route('admin.dashboard'))->assertViewHas('overview', function (array $overview) {
        expect($overview['assigned'])->toBe(2)->and($overview['pending'])->toBe(2);

        return true;
    });
});

it('matches the count on the sidebar link', function () {
    $riley = userWithRole('Sourcing');
    splitAmong(sourcingRfq('One'), [1 => $riley, 2 => $riley, 3 => $riley])->completeSourcingPart(2);
    splitAmong(sourcingRfq('Two'), [1 => $riley]);

    $response = test()->actingAs($riley)->get(route('admin.dashboard'));

    $badge = DB::table('rfq_user')->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
        ->where('rfqs.status', 'Pending')->where('rfq_user.user_id', $riley->id)->whereNull('rfq_user.completed_at')->count();

    $response->assertViewHas('overview', function (array $overview) use ($badge) {
        expect($overview['pending'])->toBe($badge)->toBe(3);

        return true;
    });
});

it('lists what they were given most recently, newest first, each part on its own line', function () {
    $riley = userWithRole('Sourcing');

    $older = splitAmong(sourcingRfq('Older job', ['rfq_number' => 'RFQ1001']), [1 => $riley, 2 => $riley]);
    DB::table('rfq_user')->where('rfq_id', $older->id)->update(['created_at' => now()->subDays(3)]);

    $newer = splitAmong(sourcingRfq('Newer job', ['rfq_number' => 'RFQ1002']), [1 => $riley]);
    DB::table('rfq_user')->where('rfq_id', $newer->id)->update(['created_at' => now()->subHour()]);

    test()->actingAs($riley)->get(route('admin.dashboard'))
        ->assertSee('RFQ1001-P1 of P2')
        ->assertSee('RFQ1001-P2 of P2')
        ->assertSee('Newer job')
        ->assertViewHas('overview', function (array $overview) {
            expect($overview['recent']->pluck('label')->all())->toBe(['RFQ1002', 'RFQ1001-P1 of P2', 'RFQ1001-P2 of P2']);

            return true;
        });
});

it('keeps the recent list to six', function () {
    $riley = userWithRole('Sourcing');
    foreach (range(1, 8) as $i) {
        splitAmong(sourcingRfq("Job {$i}"), [1 => $riley]);
    }

    test()->actingAs($riley)->get(route('admin.dashboard'))->assertViewHas('overview', function (array $overview) {
        expect($overview['recent'])->toHaveCount(6)->and($overview['assigned'])->toBe(8);

        return true;
    });
});

it('puts what was sent back, with the reason, and what is with Data Entry in front of them', function () {
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');

    $split = splitAmong(sourcingRfq('Split job', ['rfq_number' => 'RFQ1001']), [1 => $riley, 2 => $riley]);
    $split->completeSourcingPart(1);
    $split->refresh()->completeSourcingPart(2);
    $split->refresh()->returnSourcingPart(2, 'Supplier quote is missing prices', $dataEntry);

    test()->actingAs($riley)->get(route('admin.dashboard'))
        ->assertSee('Supplier quote is missing prices')
        ->assertSee('You completed it')
        ->assertViewHas('overview', function (array $overview) {
            expect($overview['returnedParts']->pluck('label')->all())->toBe(['RFQ1001-P2 of P2'])
                ->and($overview['inReviewParts']->pluck('label')->all())->toBe(['RFQ1001-P1 of P2']);

            return true;
        });
});

it('offers Mark Complete on what is still theirs, and nowhere else', function () {
    $riley = userWithRole('Sourcing');

    $split = splitAmong(sourcingRfq('Split job'), [1 => $riley, 2 => $riley]);
    $split->completeSourcingPart(1);

    $html = test()->actingAs($riley)->get(route('admin.dashboard'))->getContent();

    // Only P2 is still to do — and it asks for a comment before completing.
    expect(substr_count($html, 'js-complete"'))->toBe(1)
        ->and($html)->toContain('data-part="2"')
        ->toContain('data-return-to="dashboard"')
        ->toContain('id="completeModal"');
});

it('completes a part from the dashboard and comes back to it', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(sourcingRfq('Split job'), [1 => $riley, 2 => $riley]);

    test()->actingAs($riley)
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Quotes attached', 'return_to' => 'dashboard'])
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHas('status');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->completed_at)->toBeNull();
});

it('tells them about their own parts, not the company\'s', function () {
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');

    // The company has RFQs nobody's assigned — that's Operations' worry, not a Sourcing member's.
    Rfq::factory()->count(3)->create(['priority_level' => 'Urgent']);

    $split = splitAmong(sourcingRfq('Split job', ['priority_level' => 'Urgent']), [1 => $riley, 2 => $riley]);
    $split->completeSourcingPart(2);
    $split->refresh()->returnSourcingPart(2, 'Wrong model', $dataEntry);

    test()->actingAs($riley)->get(route('admin.dashboard'))
        ->assertSee('1 part was sent back')
        ->assertSee('2 urgent parts are waiting')
        ->assertSee('2 parts are waiting on you')
        ->assertDontSee('awaiting Operations')
        ->assertDontSee('awaiting Sourcing');
});

it('says so when there is nothing on their plate', function () {
    test()->actingAs(userWithRole('Sourcing'))->get(route('admin.dashboard'))
        ->assertSee('Nothing has been assigned to you yet.')
        ->assertSee('Nothing has been sent back.')
        ->assertSee('Nothing is waiting on Data Entry.')
        ->assertSee("You're all caught up!", false);
});
