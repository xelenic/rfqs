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
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Monday 5 October 2026 at $time, in Colombo — working 08:30–17:00, lunch
 * 12:30–13:30.
 */
function onMonday(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * Riley's parts, all theirs from 08:30: RFQ9101 (Urgent, 4h to source),
 * RFQ9102 (Medium, 16h), RFQ9103 (High) — with Data Entry from 09:00 and
 * sent back to finalize at 10:00 (30m to finalize) — and RFQ9104 (Medium),
 * with Data Entry since 09:00. Seen at 14:30.
 */
function rileysTargets(): User
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $morgan = userWithRole('Data Entry');

    onMonday('08:30');
    $part = fn (string $number, string $priority) => splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'subject' => "Job {$number}", 'priority_level' => $priority]), [1 => $riley]);
    $part('RFQ9101', 'Urgent');
    $part('RFQ9102', 'Medium');
    $finalizing = $part('RFQ9103', 'High');
    $withDataEntry = $part('RFQ9104', 'Medium');

    onMonday('09:00');
    $finalizing->refresh()->completeSourcingPart(1, 'Prices in', null, 5);
    $withDataEntry->refresh()->completeSourcingPart(1, 'Prices in', null, 5);
    onMonday('10:00');
    $finalizing->refresh()->completeDataEntryPart(1, $morgan, 'Entered', 5);

    onMonday('14:30');

    return $riley;
}

it('shows each part on their clock against its target, as Admin\'s ongoing tasks do', function () {
    $riley = rileysTargets();

    $html = test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()
        ->assertSeeInOrder(['<th>Status</th>', '<th>Time vs target</th>'], false)
        ->getContent();
    $cell = fn (string $label) => Str::betweenFirst($html, 'data-time-vs-target="'.$label.'"', '</td>');

    // 08:30–12:30 and 13:30–14:30: 5h of working time.
    expect($cell('RFQ9101'))->toContain('data-time-state="overdue"')->toContain('1h over 4h')->toContain('Sourcing target ·</span> <span class="task-progress-state text-nowrap">Overdue</span>')
        ->and($cell('RFQ9102'))->toContain('data-time-state="on_track"')->toContain('5h of 16h')->toContain('On track')
        // Finalizing since 10:00: 3h 30m against 30m.
        ->and($cell('RFQ9103'))->toContain('data-time-state="overdue"')->toContain('3h over 30m')->toContain('Finalize target')
        // With Data Entry: not on their clock.
        ->and($cell('RFQ9104'))->toContain('With Data Entry')->not->toContain('task-progress');
});

it('says on Pending how many of theirs are overdue', function () {
    $riley = rileysTargets();

    test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()
        ->assertSeeInOrder(['Pending', '3', '2 overdue · 1 urgent']);
});

it('reads due soon once under a quarter of the target is left', function () {
    $riley = rileysTargets();
    onMonday('13:15');

    $html = test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()->getContent();

    // 4h of the Urgent one's 4h gone by lunch: none left, not yet over.
    expect(Str::betweenFirst($html, 'data-time-vs-target="RFQ9101"', '</td>'))->toContain('data-time-state="due_soon"')->toContain('4h of 4h')->toContain('Due soon');
});
