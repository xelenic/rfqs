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
});

/**
 * Travels to $time ("H:i") on Monday 5 October in Colombo — 08:30–17:00,
 * lunch 12:30–13:30.
 */
function finalizeClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ6001, High priority, its one part with $riley — through Sourcing, and
 * sent to finalize by Data Entry at 10:00.
 */
function handedBackToFinalize(User $riley): Rfq
{
    finalizeClock('09:00');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ6001', 'priority_level' => 'High']), [1 => $riley]);
    $rfq->refresh()->completeSourcingPart(1);

    $morgan = userWithRole('Data Entry');
    finalizeClock('09:20');
    startDataEntryOn($rfq, 1, $morgan);
    finalizeClock('10:00');
    $rfq->refresh()->completeDataEntryPart(1, $morgan, 'Entered', 3);

    return $rfq->refresh();
}

it('starts with a Finalize target per priority, in working minutes', function () {
    expect(Setting::finalizeTargets())->toBe(['Trivial' => 240, 'Minimal' => 180, 'Low' => 120, 'Medium' => 60, 'High' => 30, 'Urgent' => 15, 'Critical' => 15]);
});

it('counts a part Data Entry sent back down to its Finalize target, on Sourcing\'s list', function () {
    $riley = userWithRole('Sourcing');
    $rfq = handedBackToFinalize($riley);

    finalizeClock('10:10');
    expect($rfq->finalizeCountdown(1))->toBe(['target' => 1800, 'remaining' => 1200]);

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    $row = Str::betweenFirst($html, '<td class="text-nowrap">RFQ6001</td>', '</tr>');

    expect($row)->toContain('data-remaining="1200" data-target="1800"')
        ->toContain('data-running-title="Working time left to finalize it · '.e("High's Finalize target").'"')
        ->not->toContain('To finalize ·')
        ->not->toContain('<span class="text-muted-soft">—</span>');
});

it('goes overdue past its target, and stops once it\'s finalized', function () {
    $riley = userWithRole('Sourcing');
    $rfq = handedBackToFinalize($riley);

    finalizeClock('10:45');
    expect($rfq->finalizeCountdown(1)['remaining'])->toBe(-900);

    $rfq->finalizePart(1);
    expect($rfq->refresh()->finalizeCountdown(1))->toBeNull();
});

it('lets an Admin set the Finalize targets on their own tab', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'finalize-targets']))->assertOk()
        ->assertSee('action="'.route('admin.settings.finalize-targets').'"', false)
        ->assertSee('id="fin-target-urgent"', false);

    test()->actingAs($admin)->patch(route('admin.settings.finalize-targets'), ['targets' => ['Trivial' => '4', 'Minimal' => '3.5', 'Low' => '3', 'Medium' => '1.5', 'High' => '0.75', 'Urgent' => '0.25', 'Critical' => '0.25']])
        ->assertSessionHas('status', 'Finalize targets saved.')
        ->assertSessionHas('settings_tab', 'finalize-targets');

    expect(Setting::finalizeTargets())->toBe(['Trivial' => 240, 'Minimal' => 210, 'Low' => 180, 'Medium' => 90, 'High' => 45, 'Urgent' => 15, 'Critical' => 15]);

    test()->actingAs($admin)->from(route('admin.settings.edit'))
        ->patch(route('admin.settings.finalize-targets'), ['targets' => ['Trivial' => '4', 'Minimal' => '3.5', 'Low' => '3', 'Medium' => 'soon', 'High' => '1', 'Urgent' => '0.1', 'Critical' => '0.25']])
        ->assertSessionHasErrorsIn('finalize_targets', ['targets.Medium', 'targets.Urgent']);

    test()->actingAs(userWithRole('Sourcing'))->patch(route('admin.settings.finalize-targets'), ['targets' => ['Trivial' => '4', 'Minimal' => '3.5', 'Low' => '1', 'Medium' => '1', 'High' => '1', 'Urgent' => '1', 'Critical' => '0.25']])
        ->assertForbidden();
});
