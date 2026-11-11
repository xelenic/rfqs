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
    it('lets an Admin set their expected RFQ count and save it, for past days too the first time', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $admin = userWithRole('Admin');

        test()->actingAs($admin)->get(route('admin.users.show', $riley))->assertOk()
            ->assertSee('Expected RFQ count')
            ->assertSee('<option value="" selected>None</option>', false)
            ->assertDontSee('id="expected-rfqs-from"', false);

        test()->actingAs($admin)->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => '8'])
            ->assertRedirect(route('admin.users.show', $riley))
            ->assertSessionHas('status', 'Riley Chen\'s expected RFQ count is 8 a working day.');

        expect(SourcingExpectedRfq::query()->sole())
            ->user_id->toBe($riley->id)
            ->expected_rfqs->toBe(8)
            ->starts_on->toBe(SourcingExpectedRfq::FROM_THE_START)
            ->set_by->toBe($admin->id)
            // The first count: past days have it too.
            ->and(expectedOnSheet('Riley Chen', '2026-09-28'))->toBe('8');

        test()->actingAs($admin)->get(route('admin.users.show', $riley))->assertOk()
            ->assertSee('<option value="8" selected>8</option>', false);

        // A change counts from today; past days keep 8.
        test()->actingAs($admin)->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => '10']);
        expect(expectedOnSheet('Riley Chen', '2026-10-05'))->toBe('10')
            ->and(expectedOnSheet('Riley Chen', '2026-10-02'))->toBe('8');

        // Changed again the same day, in place; blank sets none.
        test()->actingAs($admin)->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => ''])
            ->assertSessionHas('status', 'Riley Chen has no expected RFQ count now.');
        expect(SourcingExpectedRfq::query()->count())->toBe(2)
            ->and(expectedOnSheet('Riley Chen', '2026-10-05'))->toBe('—');
    });

    it('is the Admin\'s to set, only for someone in Sourcing, and read-only for anyone else', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $hr = userWithRole('HR Executive');

        test()->actingAs($hr)->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => '8'])->assertForbidden();
        test()->actingAs(userWithRole('Admin'))->patch(route('admin.users.expected-rfqs', userWithRole('Data Entry')), ['expected_rfqs' => '8'])->assertStatus(422);
        test()->actingAs(userWithRole('Admin'))->patch(route('admin.users.expected-rfqs', $riley), ['expected_rfqs' => '-1'])
            ->assertSessionHasErrorsIn('expected_rfqs', ['expected_rfqs']);
        expect(SourcingExpectedRfq::query()->count())->toBe(0);

        SourcingExpectedRfq::setFor($riley->id, 6, null);

        test()->actingAs($hr)->get(route('admin.users.show', $riley))->assertOk()
            ->assertSee('6 a working day')
            ->assertDontSee('name="expected_rfqs"', false);

        // Nobody else's page has it.
        test()->actingAs(userWithRole('Admin'))->get(route('admin.users.show', userWithRole('Data Entry')))->assertOk()
            ->assertDontSee('id="user-expected-rfqs"', false);
    });
});

describe('on the Sourcing Summary', function () {
    it('counts their assigned count on each of their working days, over anything typed in', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $sam = expectedSourcingMember('Sam Rivera');
        SourcingExpectedRfq::put($riley->id, 8, '2026-09-01', null);
        $typed = fn (User $person, string $day, int $figure) => SourcingDayFigure::factory()->create(['user_id' => $person->id, 'day' => $day, 'expected_rfqs' => $figure, 'successful_calls' => null, 'unanswered_calls' => null, 'unattended_calls' => null]);

        // Monday: their assigned count; Sunday: not a working day; Sam has none.
        expect(expectedOnSheet('Riley Chen', '2026-10-05'))->toBe('8')
            ->and(expectedOnSheet('Riley Chen', '2026-10-04'))->toBe('—')
            ->and(expectedOnSheet('Sam Rivera', '2026-10-05'))->toBe('—')
            // Mon 28 Sep – Sun 4 Oct: five working days.
            ->and(expectedOnSheet('Riley Chen', '2026-09-28', '2026-10-04'))->toBe('40');

        // Typed in for a working day, the assigned count still counts; on a day off, what's typed in does.
        $typed($riley, '2026-09-30', 2);
        $typed($riley, '2026-10-04', 3);
        // Sam, with nothing assigned, has what's typed in.
        $typed($sam, '2026-10-05', 4);

        expect(expectedOnSheet('Riley Chen', '2026-09-30'))->toBe('8')
            ->and(expectedOnSheet('Riley Chen', '2026-10-04'))->toBe('3')
            ->and(expectedOnSheet('Riley Chen', '2026-09-28', '2026-10-04'))->toBe('43')
            ->and(expectedOnSheet('Sam Rivera', '2026-10-05'))->toBe('4');
    });

    it('keeps past days\' figure when the assigned count changes', function () {
        $riley = expectedSourcingMember('Riley Chen');
        SourcingExpectedRfq::put($riley->id, 8, '2026-09-01', null);
        SourcingExpectedRfq::put($riley->id, 10, '2026-10-01', null);

        // Mon–Wed at 8, Thu–Fri at 10.
        expect(expectedOnSheet('Riley Chen', '2026-09-28', '2026-10-04'))->toBe('44')
            ->and(expectedOnSheet('Riley Chen', '2026-09-30'))->toBe('8')
            ->and(expectedOnSheet('Riley Chen', '2026-10-01'))->toBe('10');
    });

    it('shows the day\'s sheet an assigned count as it is, and a box for anyone without one', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $sam = expectedSourcingMember('Sam Rivera');
        SourcingExpectedRfq::setFor($riley->id, 8, null);
        $ops = userWithRole('HBDs Operations');
        $sheet = fn () => test()->actingAs($ops)->get(route('admin.reports.sourcing-summary.sheet', ['from' => '2026-10-05', 'to' => '2026-10-05']))->assertOk()->getContent();
        $cell = fn (string $html, string $name) => Str::betweenFirst(Str::after($html, 'data-sourcing-expected'), '<td class="fw-semibold text-nowrap">'.$name.'</td>', '</td>');

        // Riley: his 8, assigned, and nothing to type; Sam: a box.
        expect($cell($sheet(), 'Riley Chen'))->toContain('<span class="fw-semibold" data-expected-assigned>8</span>')->toContain('Assigned')->not->toContain('<input')
            ->and($cell($sheet(), 'Sam Rivera'))->toContain('<input type="number" form="sourcing-figures-form" name="figures['.$sam->id.'][expected_rfqs]" value=""');

        // Saving the day types Sam's in, and leaves Riley's alone — his assigned count stands, and a change on his page shows.
        test()->actingAs($ops)->put(route('admin.reports.sourcing-summary.update'), [
            'day' => '2026-10-05', 'figures' => [
                $riley->id => ['successful_calls' => '4'],
                $sam->id => ['expected_rfqs' => '6', 'successful_calls' => ''],
            ],
        ])->assertSessionHasNoErrors();

        expect(SourcingDayFigure::query()->where('user_id', $riley->id)->sole()->expected_rfqs)->toBeNull()
            ->and(SourcingDayFigure::query()->where('user_id', $sam->id)->sole()->expected_rfqs)->toBe(6)
            ->and($cell($sheet(), 'Sam Rivera'))->toContain('value="6"');

        SourcingExpectedRfq::setFor($riley->id, 9, null);
        expect($cell($sheet(), 'Riley Chen'))->toContain('data-expected-assigned>9</span>')
            ->and(expectedOnSheet('Riley Chen', '2026-10-05'))->toBe('9');
    });

    it('keeps a figure typed in before a count was assigned, without counting it', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $ops = userWithRole('HBDs Operations');
        SourcingDayFigure::factory()->create(['user_id' => $riley->id, 'day' => '2026-10-05', 'expected_rfqs' => 2, 'successful_calls' => 1, 'unanswered_calls' => null, 'unattended_calls' => null]);
        SourcingExpectedRfq::setFor($riley->id, 8, null);

        test()->actingAs($ops)->put(route('admin.reports.sourcing-summary.update'), [
            'day' => '2026-10-05', 'figures' => [$riley->id => ['successful_calls' => '5']],
        ])->assertSessionHasNoErrors();

        expect(SourcingDayFigure::query()->sole())->expected_rfqs->toBe(2)->successful_calls->toBe(5)
            ->and(expectedOnSheet('Riley Chen', '2026-10-05'))->toBe('8');
    });

    it('adds the assigned counts into the list of days', function () {
        $riley = expectedSourcingMember('Riley Chen');
        $sam = expectedSourcingMember('Sam Rivera');
        $priya = expectedSourcingMember('Priya Desai');
        SourcingExpectedRfq::put($riley->id, 8, '2026-09-01', null);
        SourcingExpectedRfq::put($sam->id, 5, '2026-09-01', null);
        // Sam's calls list the day; his 2 typed in doesn't count over his assigned 5. Priya's 3 does: she has none assigned.
        SourcingDayFigure::factory()->create(['user_id' => $sam->id, 'day' => '2026-10-05', 'expected_rfqs' => 2, 'successful_calls' => 4, 'unanswered_calls' => null, 'unattended_calls' => null]);
        SourcingDayFigure::factory()->create(['user_id' => $priya->id, 'day' => '2026-10-05', 'expected_rfqs' => 3, 'successful_calls' => null, 'unanswered_calls' => null, 'unattended_calls' => null]);

        $html = test()->actingAs(userWithRole('HR Executive'))->get(route('admin.reports.sourcing-summary'))->assertOk()->getContent();
        $row = Str::betweenFirst($html, '<tr data-day="2026-10-05">', '</tr>');
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

        // Day, people, three calls, then expected: 8 + 5 + 3.
        expect(trim(strip_tags($cells[1][5])))->toBe('16');
    });
});
