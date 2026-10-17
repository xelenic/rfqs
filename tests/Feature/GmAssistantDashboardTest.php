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

    gmaDashClock('08:40');
});

/**
 * Travels to $time ("H:i") on Monday 5 October in Colombo.
 */
function gmaDashClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ $number at $priority, its one part through to GM Assistant — the Head
 * approving it now.
 */
function atGmAssistant(string $number, string $priority = 'Medium'): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'priority_level' => $priority, 'subject' => "Subject of {$number}"]), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $dataEntry = userWithRole('Data Entry');
    startDataEntryOn($rfq, 1, $dataEntry);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry, 'Entered', 2);
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));

    return $rfq->refresh();
}

function gmAssistantDashboard(User $assistant): TestResponse
{
    return test()->actingAs($assistant)->get(route('admin.dashboard', ['period' => 'today']))->assertOk();
}

it('gives GM Assistant a dashboard of their own step — submitting, not approving', function () {
    gmAssistantDashboard(userWithRole('GM Assistant'))
        ->assertViewIs('admin.dashboard.gm-assistant')
        ->assertSee('Awaiting your submit')
        ->assertSee('Waiting for you to submit')
        ->assertSee('Avg. time to submit')
        ->assertSee('Sent on by Head of Business Development')
        ->assertSee('Sent back to you')
        ->assertDontSee('Awaiting your approval');
});

it('shows each waiting part\'s time left against their target, overdue ones called out', function () {
    $assistant = userWithRole('GM Assistant');
    // High: an hour. Urgent: half an hour.
    atGmAssistant('RFQ7101', 'High');
    atGmAssistant('RFQ7102', 'Urgent');

    gmaDashClock('09:25');
    $response = gmAssistantDashboard($assistant);
    $waiting = Str::betweenFirst($response->getContent(), 'id="gma-waiting"', '</table>');

    expect($response->viewData('overview'))->toMatchArray(['waitingOverdue' => 1, 'waitingUrgent' => 1])
        ->and($waiting)->toContain('>Time left</th>')
        ->toContain('15m left')
        ->toContain('Overdue by 15m');

    $response->assertSee('1 part is past your target')
        ->assertSee('1 overdue · 1 urgent');
});

it('counts what they submitted and sent back, and what the General Manager sent back to them', function () {
    $assistant = userWithRole('GM Assistant');
    $submitted = atGmAssistant('RFQ7201');
    $sentBack = atGmAssistant('RFQ7202');
    $returned = atGmAssistant('RFQ7203');

    gmaDashClock('09:40');
    $submitted->refresh()->recordGmAssistantPart(1, $assistant);
    gmaDashClock('09:50');
    $sentBack->refresh()->rejectPartToStage(1, 'head_of_bd_review', 'Missing the PO', $assistant, 'gm_assistant');

    // The General Manager sends one back to GM Assistant.
    $returned->refresh()->recordGmAssistantPart(1, $assistant);
    gmaDashClock('10:00');
    $returned->refresh()->rejectPartToStage(1, 'gm_assistant', 'Add the delivery terms', userWithRole('General Manager'), 'gm_review');

    gmaDashClock('10:10');
    $response = gmAssistantDashboard($assistant);
    $overview = $response->viewData('overview');

    // The one the General Manager sent back isn't a submit any more: it's theirs again.
    expect($overview)->toMatchArray(['approved' => 1, 'average' => 3600, 'returned' => 1])
        ->and($overview['returnedToMe']->pluck('rfq_number')->all())->toBe(['RFQ7203'])
        ->and($overview['decisions']['series'][0]['label'])->toBe('Submitted');

    $response->assertSeeInOrder(['Your latest decisions', 'Sent back', 'RFQ7202', 'Submitted'])
        ->assertSee('Add the delivery terms')
        ->assertSee('1 RFQ sent back to you');
});
