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
 * of Business Development approving it at 10:00, and GM Assistant starting
 * on it then too (unless ! $started).
 */
function withGmAssistant(bool $started = true): Rfq
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

    if ($started) {
        startGmAssistantOn($rfq, 1);
    }

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

it('keeps GM Assistant\'s per-priority targets off the page — their tab is GM Assistant Time — but takes them still', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'gm-assistant-targets']))->assertOk()
        ->assertSee('GM Assistant Time')
        ->assertSee('Time per line item')
        ->assertDontSee('action="'.route('admin.settings.gm-assistant-targets').'"', false)
        ->assertDontSee('id="gma-target-urgent"', false);

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

// ---- Per line item ---------------------------------------------------------

it('starts with GM Assistant\'s target by priority, a minute a line item when it\'s switched on', function () {
    expect(Setting::gmAssistantPerItem())->toBe(['enabled' => false, 'seconds' => 60]);
});

it('works a part out per line item when that\'s on — the line items Data Entry added, else those Sourcing said to add', function () {
    Setting::putGmAssistantPerItem(['enabled' => true, 'seconds' => 90]);

    // Data Entry added 2 line items (withGmAssistant()): 2 × 1.5 min.
    $added = withGmAssistant();
    expect($added->gmAssistantTargetSeconds(1))->toBe(180)
        ->and($added->gmAssistantTargetBreakdown(1))->toBe(['items' => 2, 'perItem' => 90, 'seconds' => 180, 'source' => 'added']);

    // None added: Sourcing's 30 to add.
    $added->assigneeForPart(1)->pivot->update(['data_entry_entries' => 0, 'line_items_to_add' => 30]);
    expect($added->refresh()->gmAssistantTargetSeconds(1))->toBe(30 * 90)
        ->and($added->gmAssistantTargetBreakdown(1)['source'])->toBe('to_add');

    // Neither: High's hour.
    $added->assigneeForPart(1)->pivot->update(['line_items_to_add' => null]);
    expect($added->refresh()->gmAssistantTargetSeconds(1))->toBe(3600);

    // Switched off: High's hour whatever the line items.
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 90]);
    $added->assigneeForPart(1)->pivot->update(['data_entry_entries' => 40]);
    expect($added->refresh()->gmAssistantTargetSeconds(1))->toBe(3600);
});

it('counts the list down from the line items, the formula on hover', function () {
    Setting::putGmAssistantPerItem(['enabled' => true, 'seconds' => 60]);
    $rfq = withGmAssistant();
    $rfq->assigneeForPart(1)->pivot->update(['data_entry_entries' => 45]);

    gmaClock('10:15');
    expect($rfq->refresh()->gmAssistantCountdown(1))->toBe(['target' => 2700, 'remaining' => 1800]);

    $html = test()->actingAs(userWithRole('GM Assistant'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    expect(Str::betweenFirst($html, '<td class="text-nowrap">RFQ9301</td>', '</tr>'))
        ->toContain('data-remaining="1800" data-target="2700"')
        ->toContain('Working time left to submit it to the General Manager · 45 line items × 1 min = 45m');
});

it('lets an Admin switch it on and set the time a line item takes', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'gm-assistant-targets']))->assertOk()
        ->assertSee('action="'.route('admin.settings.gm-assistant-per-item').'"', false)
        ->assertSee("Set GM Assistant's target per line item", false);

    test()->actingAs($admin)->patch(route('admin.settings.gm-assistant-per-item'), ['enabled' => '1', 'minutes' => '1.5'])
        ->assertSessionHas('status', 'GM Assistant\'s target is now worked out per line item.')
        ->assertSessionHas('settings_tab', 'gm-assistant-targets');

    expect(Setting::gmAssistantPerItem())->toBe(['enabled' => true, 'seconds' => 90]);

    test()->actingAs($admin)->from(route('admin.settings.edit'))
        ->patch(route('admin.settings.gm-assistant-per-item'), ['enabled' => '1', 'minutes' => '0.3'])
        ->assertSessionHasErrorsIn('gm_assistant_per_item', ['minutes']);

    test()->actingAs(userWithRole('GM Assistant'))->patch(route('admin.settings.gm-assistant-per-item'), ['enabled' => '0', 'minutes' => '1'])
        ->assertForbidden();
});
