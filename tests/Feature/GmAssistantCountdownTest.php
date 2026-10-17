<?php

use App\Models\Rfq;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Travels to $time ("H:i") on Monday 5 October in Colombo — 08:30–17:00,
 * lunch 12:30–13:30.
 */
function gmaClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ9301, High priority, its one part through to GM Assistant — the Head
 * of Business Development approving it at 10:00.
 */
function withGmAssistant(): Rfq
{
    gmaClock('08:40');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9301', 'priority_level' => 'High']), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $dataEntry = userWithRole('Data Entry');
    startDataEntryOn($rfq, 1, $dataEntry);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry, 'Entered', 2);
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));

    gmaClock('10:00');
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));

    return $rfq->refresh();
}

it('starts with a GM Assistant target per priority, in working minutes', function () {
    expect(Setting::gmAssistantTargets())->toBe(['Low' => 240, 'Medium' => 120, 'High' => 60, 'Urgent' => 30]);
});

it('counts a part down to GM Assistant\'s target on their list', function () {
    $rfq = withGmAssistant();

    gmaClock('10:20');
    expect($rfq->gmAssistantCountdown(1))->toBe(['target' => 3600, 'remaining' => 2400]);

    $html = test()->actingAs(userWithRole('GM Assistant'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    $row = Str::betweenFirst($html, '<td class="text-nowrap">RFQ9301</td>', '</tr>');

    expect($html)->toContain('>Time Left</th>')
        ->toContain('id="countdown-schedule"')
        ->and($row)->toContain('data-remaining="2400" data-target="3600"')
        ->toContain('40m left');
});

it('goes overdue past its target, and stops once it\'s submitted', function () {
    $rfq = withGmAssistant();

    gmaClock('11:15');
    expect($rfq->gmAssistantCountdown(1)['remaining'])->toBe(-900);

    $rfq->recordGmAssistantPart(1, userWithRole('GM Assistant'));
    expect($rfq->refresh()->gmAssistantCountdown(1))->toBeNull();
});

it('lets an Admin set the GM Assistant targets on their own tab', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'gm-assistant-targets']))->assertOk()
        ->assertSee('action="'.route('admin.settings.gm-assistant-targets').'"', false)
        ->assertSee('id="gma-target-urgent"', false);

    test()->actingAs($admin)->patch(route('admin.settings.gm-assistant-targets'), ['targets' => ['Low' => '6', 'Medium' => '3', 'High' => '1.5', 'Urgent' => '0.5']])
        ->assertSessionHas('status', 'GM Assistant targets saved.')
        ->assertSessionHas('settings_tab', 'gm-assistant-targets');

    expect(Setting::gmAssistantTargets())->toBe(['Low' => 360, 'Medium' => 180, 'High' => 90, 'Urgent' => 30]);

    test()->actingAs($admin)->from(route('admin.settings.edit'))
        ->patch(route('admin.settings.gm-assistant-targets'), ['targets' => ['Low' => '6', 'Medium' => 'soon', 'High' => '1', 'Urgent' => '0.1']])
        ->assertSessionHasErrorsIn('gm_assistant_targets', ['targets.Medium', 'targets.Urgent']);

    test()->actingAs(userWithRole('GM Assistant'))->patch(route('admin.settings.gm-assistant-targets'), ['targets' => ['Low' => '1', 'Medium' => '1', 'High' => '1', 'Urgent' => '1']])
        ->assertForbidden();
});
