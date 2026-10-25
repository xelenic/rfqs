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
 * Monday 5 October 2026 at $time, in Colombo — 08:30–17:00, lunch 12:30–13:30.
 */
function timeCardAt(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ9701 ("Pumps", High), its one part with GM Assistant from 09:00: 24
 * line items to add, 22 added, Olivia's 23 actual ones and Hana's (the
 * Head's) 30 finalized (unless ! $counted).
 *
 * @return array{rfq: Rfq, gma: User}
 */
function withGmAssistantToTime(bool $counted = true): array
{
    timeCardAt('08:40');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9701', 'subject' => 'Pumps', 'priority_level' => 'High']), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1, null, null, 24);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'), 'Entered', 22);
    $rfq->refresh()->finalizePart(1, 22);
    $rfq->refresh()->approveSeniorOpsPart(1, tap(userWithRole('Senior Operations'))->update(['name' => 'Olivia Park']), $counted ? 23 : null);
    timeCardAt('09:00');
    $rfq->refresh()->approveHeadOfBdPart(1, tap(userWithRole('Head of Business Development'))->update(['name' => 'Hana Head']), $counted ? 30 : null);

    return ['rfq' => $rfq->refresh(), 'gma' => tap(userWithRole('GM Assistant'))->update(['name' => 'Gail Assist'])];
}

/**
 * The text of $rfq's time card on GM Assistant's list, and the cell it's on.
 *
 * @return array{0: string, 1: string}
 */
function timeCardOnList(User $gma, Rfq $rfq): array
{
    $html = test()->actingAs($gma)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    $card = Str::betweenFirst($html, '<template id="gma-time-'.$rfq->id.'-p1">', '</template>');

    return [Str::squish(strip_tags($card)), Str::betweenFirst($html, 'data-hover-card="gma-time-'.$rfq->id.'-p1"', '</span>')];
}

it('explains a running countdown on hover — the line items, who counted them, the time a line item takes, the start, and the time used and left', function () {
    ['rfq' => $rfq, 'gma' => $gma] = withGmAssistantToTime();
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 120]);
    startGmAssistantOn($rfq, 1, $gma);
    timeCardAt('09:20');

    [$card, $cell] = timeCardOnList($gma, $rfq);

    expect($cell)->toContain('data-countdown')->not->toMatch('/\\stitle=/')
        ->and($card)
        ->toContain('GM Assistant · RFQ9701 40m left')
        ->toContain('Target = 30 Finalized Line Items × 2 min a line item = 1h')
        ->toContain('Time left = target − working time since GM Assistant\'s Start')
        ->toContain('Line items 30 Finalized Line Items · Hana Head (Head of Business Development) · Oct 05, 2026 9:00 AM')
        ->toContain('Time a line item takes 2 min Settings → GM Assistant Time')
        ->toContain('Countdown Running Started Oct 05, 2026 9:00 AM by Gail Assist.')
        ->toContain('Working time used 20m Time left 40m left')
        ->not->toContain('Actual');
});

it('explains one by priority, with nothing to go by per line item, that hasn\'t started', function () {
    ['rfq' => $rfq, 'gma' => $gma] = withGmAssistantToTime(counted: false);
    $rfq->assignees()->updateExistingPivot($rfq->assigneeForPart(1)->id, ['data_entry_entries' => null, 'line_items_to_add' => null]);

    [$card] = timeCardOnList($gma, $rfq->refresh());

    expect($card)
        ->toContain('Target = P3 – High\'s GM Assistant target = 1h')
        ->toContain('No line items to go by — so it goes by priority.')
        ->toContain('Not used — the target is by priority, Settings → GM Assistant Targets')
        ->toContain('Countdown Not started Starts when GM Assistant presses Start')
        ->toContain('Working time used 0m Time left 1h left');
});

it('says when it\'s going by an earlier count, for want of Finalized Line Items', function () {
    ['rfq' => $rfq, 'gma' => $gma] = withGmAssistantToTime(counted: false);
    Setting::putGmAssistantPerItem(['enabled' => true, 'seconds' => 60]);

    [$card] = timeCardOnList($gma, $rfq);

    expect($card)->toContain('Target = 22 Line Items Added × 1 min a line item = 22m')
        ->toContain('No Finalized Line Items from the Head of Business Development — so it goes by these.');
});

it('explains a paused one — where it stopped, and why', function () {
    ['rfq' => $rfq, 'gma' => $gma] = withGmAssistantToTime();
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 120]);
    startGmAssistantOn($rfq, 1, $gma);
    timeCardAt('09:10');
    $rfq->refresh()->pauseGmAssistantPart(1, $gma, 'Waiting on the brochure');
    timeCardAt('10:00');

    [$card] = timeCardOnList($gma, $rfq->refresh());

    expect($card)->toContain('Countdown Paused Paused Oct 05, 2026 9:10 AM: Waiting on the brochure — carries on from here when it\'s resumed.')
        ->toContain('Working time used 10m Time left 50m left');
});

it('explains it on GM Assistant\'s dashboard too', function () {
    ['rfq' => $rfq, 'gma' => $gma] = withGmAssistantToTime();
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 120]);
    startGmAssistantOn($rfq, 1, $gma);
    timeCardAt('09:20');

    $html = test()->actingAs($gma)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('data-hover-card="gma-dash-time-'.$rfq->id.'-p1"', false)
        ->getContent();

    expect(Str::squish(strip_tags(Str::betweenFirst($html, '<template id="gma-dash-time-'.$rfq->id.'-p1">', '</template>'))))
        ->toContain('Target = 30 Finalized Line Items × 2 min a line item = 1h');
});
