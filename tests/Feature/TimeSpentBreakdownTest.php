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
 * Travels to $time ("H:i") on Monday 5 October in Colombo.
 */
function breakdownAt(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ7501 through a round of rework both ways, by 15:30: Sourcing 09–10,
 * Data Entry 10–11 and back to Sourcing, Sourcing 11–12, Data Entry 12–15
 * (lunch aside) and sent to finalize, and Riley's Finalize since 15:00.
 *
 * @return array{rfq: Rfq, riley: User, morgan: User}
 */
function reworkedRfq(): array
{
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');

    breakdownAt('09:00');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7501']), [1 => $riley]);
    breakdownAt('10:00');
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1, $morgan);
    breakdownAt('11:00');
    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', $morgan);
    breakdownAt('12:00');
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1, $morgan);
    breakdownAt('15:00');
    $rfq->refresh()->completeDataEntryPart(1, $morgan, null, 5);
    breakdownAt('15:30');

    return ['rfq' => $rfq->refresh()->load('steps'), 'riley' => $riley, 'morgan' => $morgan];
}

it('breaks each role\'s time down stretch by stretch, adding up to its figure', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = reworkedRfq();

    $breakdown = $rfq->timeBreakdown();
    $time = $rfq->timeSpent();

    expect(collect($breakdown['Sourcing'])->map(fn (array $stretch) => [$stretch['step'], $stretch['worker'], $stretch['counted'], $stretch['rework'], $stretch['to'] === null])->all())
        ->toBe([
            ['Sourcing', $riley->name, 3600, false, false],
            ['Sourcing', $riley->name, 3600, true, false],
            ['Finalize', $riley->name, 1800, false, true],
        ])
        ->and(collect($breakdown['Data Entry'])->map(fn (array $stretch) => [$stretch['worker'], $stretch['working'], $stretch['counted'], $stretch['rework']])->all())
        ->toBe([
            [$morgan->name, 3600, 3600, false],
            // 12–15, lunch 12:30–13:30 aside; three hours on the clock.
            [$morgan->name, 7200, 7200, true],
        ])
        ->and($breakdown['Data Entry'][1]['elapsed'])->toBe(3 * 3600)
        ->and($breakdown['Data Entry'][1]['hours'])->toBe('the company\'s working hours')
        ->and($breakdown['GM Assistant'])->toBe([]);

    foreach (['Sourcing', 'Data Entry'] as $role) {
        expect(collect($breakdown[$role])->sum('counted'))->toBe($time['roles'][$role]['seconds']);
    }
});

it('shows the breakdown on hovering a role\'s time on the Time Spent report', function () {
    ['rfq' => $rfq, 'riley' => $riley] = reworkedRfq();

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee('class="time-breakdown-trigger" tabindex="0" role="button" data-time-breakdown="time-breakdown-'.$rfq->id.'-sourcing"', false)
        ->assertSee('data-time-breakdown="time-breakdown-'.$rfq->id.'-data-entry"', false)
        // GM Assistant hasn't had it: nothing to break down.
        ->assertDontSee('data-time-breakdown="time-breakdown-'.$rfq->id.'-gm-assistant"', false)
        ->getContent();

    $sourcing = Str::betweenFirst($html, '<template id="time-breakdown-'.$rfq->id.'-sourcing">', '</template>');

    expect($sourcing)
        ->toContain('<span class="tb-term">Counted</span> = working time − on leave − awaiting attendance')
        ->toContain('<strong>1. Sourcing</strong>')
        ->toContain('<strong>3. Finalize</strong>')
        ->toContain('<span class="tb-tag">rework</span>')
        ->toContain('<span class="tb-tag tb-tag-live">in progress</span>')
        ->toContain(e($riley->name).' · the company&#039;s working hours')
        ->toContain('Mon, Oct 5 · 9:00 AM → Mon, Oct 5 · 10:00 AM · 1h clock time')
        ->toContain('→ now')
        ->toMatch('/1h\s+working\s+= <strong>1h<\/strong>/')
        ->toMatch('/1h \+ 1h \+ 30m =\s+<strong>2h 30m<\/strong> counted/')
        // Finalize is no new round: two, one of them rework.
        ->toContain('2 rounds, 1 rework')
        ->toContain('still counting up to now');
});

it('shows what isn\'t counted — a day whose attendance isn\'t in yet', function () {
    Setting::put('attendance_since', '2026-10-01');
    ['rfq' => $rfq] = reworkedRfq();

    $first = $rfq->timeBreakdown()['Sourcing'][0];
    expect($first)->toMatchArray(['working' => 3600, 'awaiting' => 3600, 'counted' => 0]);

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.time-spent'))->assertOk()->getContent();
    expect(Str::betweenFirst($html, '<template id="time-breakdown-'.$rfq->id.'-sourcing">', '</template>'))
        ->toMatch('/1h\s+working\s+− 1h awaiting attendance\s+= <strong>0m<\/strong>/');
});
