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

    hbdClock('09:00');
});

/**
 * Travels to $time ("H:i") on Monday 5 October in Colombo.
 */
function hbdClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ $number at $priority, its one part through to the Head — Senior
 * Operations approving it now.
 */
function atTheHead(string $number, string $priority = 'Medium'): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'priority_level' => $priority, 'subject' => "Subject of {$number}"]), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $dataEntry = userWithRole('Data Entry');
    startDataEntryOn($rfq, 1, $dataEntry);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry, 'Entered', 2);
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));

    return $rfq->refresh();
}

function headDashboard(User $head): TestResponse
{
    return test()->actingAs($head)->get(route('admin.dashboard', ['period' => 'today']))->assertOk();
}

it('gives the Head of Business Development a dashboard of their own review', function () {
    headDashboard(userWithRole('Head of Business Development'))
        ->assertViewIs('admin.dashboard.head-of-business-development')
        ->assertSee('Waiting for your approval')
        ->assertSee('Sent back to you')
        ->assertSee('Your decisions')
        ->assertSee('Where open RFQs are')
        ->assertSee('Sent on by Senior Operations');
});

it('lists what waits on their review — Urgent first — and counts their approvals and send-backs', function () {
    $head = userWithRole('Head of Business Development');
    atTheHead('RFQ6501', 'Low');
    hbdClock('09:30');
    atTheHead('RFQ6502', 'Urgent');
    $approve = atTheHead('RFQ6503');
    $sendBack = atTheHead('RFQ6504');

    // Approved an hour after Senior Operations' review; another sent back.
    hbdClock('10:30');
    $approve->refresh()->approveHeadOfBdPart(1, $head);
    hbdClock('10:45');
    $sendBack->refresh()->rejectPartToStage(1, 'senior_ops_review', 'Check the totals', $head, 'head_of_bd_review');

    hbdClock('11:00');
    $response = headDashboard($head);
    $overview = $response->viewData('overview');
    $waiting = Str::betweenFirst($response->getContent(), 'id="hbd-waiting"', '</table>');

    expect($overview)->toMatchArray(['approved' => 1, 'approvedRfqs' => 1, 'returned' => 1, 'average' => 3600, 'waitingUrgent' => 1])
        ->and($overview['waiting'])->toHaveCount(2)
        ->and(strpos($waiting, 'RFQ6502'))->toBeLessThan(strpos($waiting, 'RFQ6501'))
        ->and($overview['latest']->pluck('approved')->all())->toBe([false, true]);

    $response->assertSee('1 urgent part waits on your approval');
});

it('shows what the General Manager sent back to them, with why', function () {
    $head = userWithRole('Head of Business Development');
    $rfq = atTheHead('RFQ6601');
    $rfq->refresh()->approveHeadOfBdPart(1, $head);
    $rfq->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'));

    hbdClock('11:00');
    $rfq->refresh()->rejectPartToStage(1, 'head_of_bd_review', 'Margins look off', userWithRole('General Manager'), 'gm_review');

    $response = headDashboard($head);
    $returns = Str::betweenFirst($response->getContent(), 'id="hbd-returns"', '</table>');

    expect($response->viewData('overview')['returnedToMe']->pluck('rfq_number')->all())->toBe(['RFQ6601'])
        ->and($returns)->toContain('RFQ6601')->toContain('Margins look off');

    $response->assertSee('1 RFQ sent back to you');
});

it('leaves Admin their own dashboard, holding the Head\'s role too', function () {
    headDashboard(userWithRole('Admin')->assignRole('Head of Business Development'))->assertViewIs('admin.dashboard.admin');
});
