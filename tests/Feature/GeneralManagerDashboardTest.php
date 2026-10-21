<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }

    gmClock('09:00');
});

/**
 * Travels to $time ("H:i") on Monday 5 October in Colombo.
 */
function gmClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ $number at $priority, its one part through the chain up to GM
 * Assistant's submit, now — waiting on the General Manager.
 */
function atTheGm(string $number, string $priority = 'Medium'): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'priority_level' => $priority, 'subject' => "Subject of {$number}"]), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));
    $rfq->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'));

    return $rfq->refresh();
}

function gmDashboard(User $gm, string $period = 'today'): TestResponse
{
    return test()->actingAs($gm)->get(route('admin.dashboard', ['period' => $period]))->assertOk();
}

it('gives the General Manager a dashboard of their own — not the people, roles and permissions counts', function () {
    gmDashboard(userWithRole('General Manager'))
        ->assertViewIs('admin.dashboard.general-manager')
        ->assertSee('Waiting for your approval')
        ->assertSee('Your decisions')
        ->assertSee('Where open RFQs are')
        ->assertSee('RFQ Activity')
        ->assertDontSee('<div class="stat-label">Users</div>', false)
        ->assertDontSee('<div class="stat-label">Permissions</div>', false);

    // Admin keeps theirs, even holding General Manager too.
    gmDashboard(userWithRole('Admin')->assignRole('General Manager'))->assertViewIs('admin.dashboard.admin');
});

it('lists what waits on their approval — Urgent first, then longest-waiting', function () {
    atTheGm('RFQ6001', 'Low');
    gmClock('09:30');
    atTheGm('RFQ6002', 'Urgent');
    atTheGm('RFQ6003', 'Medium');
    // Still with GM Assistant: not theirs yet.
    $notYet = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ6004']), [1 => userWithRole('Sourcing')]);

    $response = gmDashboard(userWithRole('General Manager'));
    $waiting = Str::betweenFirst($response->getContent(), 'id="gm-waiting"', '</table>');

    expect($waiting)->not->toContain('RFQ6004')
        ->and(strpos($waiting, 'RFQ6002'))->toBeLessThan(strpos($waiting, 'RFQ6003'))
        ->and(strpos($waiting, 'RFQ6003'))->toBeLessThan(strpos($waiting, 'RFQ6001'))
        ->and($response->viewData('overview')['waitingUrgent'])->toBe(1);

    $response->assertSeeInOrder(['Awaiting your approval', '3', '1 urgent'])
        ->assertSee('1 urgent part waits on your approval');
});

it('counts their approvals and send-backs over the period, how long an approval took, and charts them', function () {
    $gm = userWithRole('General Manager');

    $approved = atTheGm('RFQ6101', 'High');
    $returned = atTheGm('RFQ6102');
    atTheGm('RFQ6103');

    // Approved an hour after GM Assistant's submit; another sent back.
    gmClock('10:00');
    $approved->refresh()->approveGmPart(1, $gm);
    gmClock('11:00');
    $returned->refresh()->rejectPartToStage(1, 'gm_assistant', 'Wrong payment terms', $gm, 'gm_review');

    // Someone else's approval doesn't count.
    atTheGm('RFQ6104')->approveGmPart(1, userWithRole('General Manager'));

    gmClock('11:30');
    $overview = gmDashboard($gm)->viewData('overview');

    expect($overview)->toMatchArray([
        'approved' => 1,
        'approvedRfqs' => 1,
        'returned' => 1,
        'average' => 3600,
        'returnedTo' => ['GM Assistant' => 1],
    ])
        ->and($overview['waiting'])->toHaveCount(1)
        ->and(collect($overview['decisions']['series'])->pluck('key')->all())->toBe(['approved', 'returned'])
        ->and(array_sum($overview['decisions']['series'][0]['values']))->toBe(1)
        ->and(array_sum($overview['decisions']['series'][1]['values']))->toBe(1)
        ->and($overview['latest']->pluck('approved')->all())->toBe([false, true])
        ->and($overview['latest'][0]['detail'])->toBe('to GM Assistant — Wrong payment terms');

    gmDashboard($gm)
        ->assertSee('Sent back to: GM Assistant 1')
        ->assertSeeInOrder(['Your latest decisions', 'Sent back', 'RFQ6102', 'Approved', 'RFQ6101']);
});

it('shows where the open RFQs are, and how many', function () {
    atTheGm('RFQ6201', 'Urgent');
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ6202', 'priority_level' => 'Low']), [1 => userWithRole('Sourcing')]);

    $overview = gmDashboard(userWithRole('General Manager'))->viewData('overview');

    expect($overview)->toMatchArray(['open' => 2, 'openUrgent' => 1])
        ->and($overview['priorities'])->toBe(['Critical' => 0, 'Urgent' => 1, 'High' => 0, 'Medium' => 0, 'Low' => 1, 'Minimal' => 0, 'Trivial' => 0])
        ->and(collect($overview['pipeline'])->firstWhere('label', 'General Manager')['count'])->toBe(1)
        ->and(collect($overview['pipeline'])->firstWhere('label', 'With Sourcing')['count'])->toBe(1);
});
