<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\SourcingDayFigure;
use App\Models\SourcingExpectedRfq;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }

    Permission::findOrCreate('users.view');
    Role::findByName('Admin')->givePermissionTo('users.view');
    Role::findByName('HR Executive')->givePermissionTo('users.view');

    // Monday 5 October 2026, in Colombo.
    test()->travelTo(CarbonImmutable::parse('2026-10-05 16:00', Setting::DEFAULT_TIMEZONE));
});

function expectedSourcingMember(string $name): User
{
    return tap(userWithRole('Sourcing'))->update(['name' => $name]);
}

/**
 * The Sourcing Summary's "Number of expected RFQ" for $name, from $from to $to,
 * as HR Executive (who sees the figures, not the dropdowns) reads it.
 */
function expectedOnSheet(string $name, string $from, ?string $to = null): string
{
    $html = test()->actingAs(userWithRole('HR Executive'))
        ->get(route('admin.reports.sourcing-summary.sheet', ['from' => $from, 'to' => $to ?? $from]))
        ->assertOk()
        ->getContent();
    $row = Str::betweenFirst(Str::after($html, 'data-sourcing-expected'), '<td class="fw-semibold text-nowrap">'.$name.'</td>', '</tr>');
    preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

    return trim(strip_tags($cells[1][0]));
}

describe('on a Sourcing member\'s user page', function () {
    it('lets an Admin set their usual expected RFQs, from today or another day', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $admin = userWithRole('Admin');

        test()->actingAs($admin)->get(route('admin.users.show', $riley))->assertOk()
            ->assertSee('id="user-expected-rfqs"', false)
            ->assertSee('<option value="" selected>None</option>', false)
            ->assertSee('name="starts_on" id="expected-rfqs-from" value="2026-10-05"', false);

        test()->actingAs($admin)->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => '8', 'starts_on' => '2026-10-05'])
            ->assertRedirect(route('admin.users.show', $riley))
            ->assertSessionHas('status', 'Riley Chen is expected to hand in 8 RFQs a working day from Oct 05, 2026.');

        expect(SourcingExpectedRfq::query()->sole())
            ->user_id->toBe($riley->id)
            ->expected_rfqs->toBe(8)
            ->starts_on->toBe('2026-10-05')
            ->set_by->toBe($admin->id);

        test()->actingAs($admin)->get(route('admin.users.show', $riley))->assertOk()
            ->assertSee('8 RFQs a working day</span>', false)
            ->assertSee('since Oct 05, 2026')
            ->assertSee('<option value="8" selected>8</option>', false);

        // The same day again changes it in place; blank sets none.
        test()->actingAs($admin)->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => '', 'starts_on' => '2026-10-05'])
            ->assertSessionHas('status', 'Riley Chen has no usual expected RFQs from Oct 05, 2026.');
        expect(SourcingExpectedRfq::query()->sole()->expected_rfqs)->toBeNull();
    });

    it('is the Admin\'s to set, only for someone in Sourcing, and read-only for anyone else', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $hr = userWithRole('HR Executive');

        test()->actingAs($hr)->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => '8', 'starts_on' => '2026-10-05'])->assertForbidden();
        test()->actingAs(userWithRole('Admin'))->patch(route('admin.users.expected-rfqs', userWithRole('Data Entry')), ['expected_rfqs' => '8', 'starts_on' => '2026-10-05'])->assertStatus(422);
        test()->actingAs(userWithRole('Admin'))->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => '-1', 'starts_on' => 'soon'])
            ->assertSessionHasErrorsIn('expected_rfqs', ['expected_rfqs', 'starts_on']);
        expect(SourcingExpectedRfq::query()->count())->toBe(0);

        SourcingExpectedRfq::put($riley->id, 6, '2026-10-01', null);

        test()->actingAs($hr)->get(route('admin.users.show', $riley))->assertOk()
            ->assertSee('6 RFQs a working day')
            ->assertDontSee('name="expected_rfqs"', false);

        // Nobody else's page has it.
        test()->actingAs(userWithRole('Admin'))->get(route('admin.users.show', userWithRole('Data Entry')))->assertOk()
            ->assertDontSee('id="user-expected-rfqs"', false);
    });
});

describe('on the Sourcing Summary', function () {
    it('counts their usual figure on each of their working days, unless one was set for the day', function () {
        $riley = expectedSourcingMember('Riley Chen');
        expectedSourcingMember('Sam Rivera');
        SourcingExpectedRfq::put($riley->id, 8, '2026-09-01', null);

        // Monday: their usual; Sunday: not a working day; Sam has none.
        expect(expectedOnSheet('Riley Chen', '2026-10-05'))->toBe('8')
            ->and(expectedOnSheet('Riley Chen', '2026-10-04'))->toBe('—')
            ->and(expectedOnSheet('Sam Rivera', '2026-10-05'))->toBe('—')
            // Mon 28 Sep – Sun 4 Oct: five working days.
            ->and(expectedOnSheet('Riley Chen', '2026-09-28', '2026-10-04'))->toBe('40');

        // A figure set for a day counts instead of the usual one, that day.
        SourcingDayFigure::factory()->create(['user_id' => $riley->id, 'day' => '2026-09-30', 'expected_rfqs' => 2, 'successful_calls' => null, 'unanswered_calls' => null, 'unattended_calls' => null]);

        expect(expectedOnSheet('Riley Chen', '2026-09-30'))->toBe('2')
            ->and(expectedOnSheet('Riley Chen', '2026-09-28', '2026-10-04'))->toBe('34');
    });

    it('keeps past days\' figure when the usual one changes', function () {
        $riley = expectedSourcingMember('Riley Chen');
        SourcingExpectedRfq::put($riley->id, 8, '2026-09-01', null);
        SourcingExpectedRfq::put($riley->id, 10, '2026-10-01', null);

        // Mon–Wed at 8, Thu–Fri at 10.
        expect(expectedOnSheet('Riley Chen', '2026-09-28', '2026-10-04'))->toBe('44')
            ->and(expectedOnSheet('Riley Chen', '2026-09-30'))->toBe('8')
            ->and(expectedOnSheet('Riley Chen', '2026-10-01'))->toBe('10');
    });

    it('offers the usual figure as the day\'s dropdown\'s first choice, so leaving it there keeps it', function () {
        $riley = expectedSourcingMember('Riley Chen');
        SourcingExpectedRfq::put($riley->id, 8, '2026-09-01', null);
        $ops = userWithRole('HBDs Operations');
        $dropdown = fn (string $html) => Str::betweenFirst($html, 'name="figures['.$riley->id.'][expected_rfqs]"', '</select>');

        $html = test()->actingAs($ops)->get(route('admin.reports.sourcing-summary.sheet', ['from' => '2026-10-05', 'to' => '2026-10-05']))->assertOk()->getContent();
        expect($dropdown($html))->toContain('<option value="" selected>Usual · 8</option>');

        // Saved as it is, nothing's set for the day: the usual figure stands.
        test()->actingAs($ops)->put(route('admin.reports.sourcing-summary.update'), [
            'day' => '2026-10-05', 'figures' => [$riley->id => ['expected_rfqs' => '', 'successful_calls' => '4']],
        ])->assertSessionHasNoErrors();
        expect(SourcingDayFigure::query()->sole()->expected_rfqs)->toBeNull()
            ->and(expectedOnSheet('Riley Chen', '2026-10-05'))->toBe('8');

        // A figure for the day is selected instead.
        SourcingDayFigure::query()->sole()->update(['expected_rfqs' => 3]);
        $html = test()->actingAs($ops)->get(route('admin.reports.sourcing-summary.sheet', ['from' => '2026-10-05', 'to' => '2026-10-05']))->assertOk()->getContent();
        expect($dropdown($html))->toContain('<option value="" >Usual · 8</option>')->toContain('<option value="3" selected>3</option>');
    });

    it('adds the usual figures into the list of days', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $sam = expectedSourcingMember('Sam Rivera');
        SourcingExpectedRfq::put($riley->id, 8, '2026-09-01', null);
        SourcingExpectedRfq::put($sam->id, 5, '2026-09-01', null);
        // Sam's calls list the day, with an expected figure of his own for it.
        SourcingDayFigure::factory()->create(['user_id' => $sam->id, 'day' => '2026-10-05', 'expected_rfqs' => 2, 'successful_calls' => 4, 'unanswered_calls' => null, 'unattended_calls' => null]);

        $html = test()->actingAs(userWithRole('HR Executive'))->get(route('admin.reports.sourcing-summary'))->assertOk()->getContent();
        $row = Str::betweenFirst($html, '<tr data-day="2026-10-05">', '</tr>');
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

        // Day, people, three calls, then expected: Riley's usual 8 and Sam's 2 for the day.
        expect(trim(strip_tags($cells[1][5])))->toBe('10');
    });
});
