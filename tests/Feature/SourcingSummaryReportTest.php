<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\SourcingDayFigure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }

    // Monday 5 October 2026, in Colombo.
    test()->travelTo(CarbonImmutable::parse('2026-10-05 16:00', Setting::DEFAULT_TIMEZONE));
});

function summaryUrl(array $query = []): string
{
    return route('admin.reports.sourcing-summary', $query);
}

/**
 * Riley and Sam in Sourcing. Riley finalized RFQ8101 (10 items), has RFQ8102
 * (5 items) waiting for the customer's clarification, and a part of RFQ8103
 * (8 items), whose other part is Sam's.
 *
 * @return array{riley: User, sam: User, ops: User}
 */
function summaryDay(): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $sam = tap(userWithRole('Sourcing'))->update(['name' => 'Sam Rivera']);
    $ops = userWithRole('Senior Operations');

    $finalized = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8101', 'number_of_items' => 10]), [1 => $riley]);
    $waiting = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8102', 'number_of_items' => 5]), [1 => $riley]);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8103', 'number_of_items' => 8]), [1 => $riley, 2 => $sam]);

    $finalized->completeSourcingPart(1);
    $finalized->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $finalized->refresh()->finalizePart(1);
    $waiting->changeStatus(Rfq::ON_HOLD, $ops, 'Asked the customer', true);

    return ['riley' => $riley, 'sam' => $sam, 'ops' => $ops];
}

/**
 * The text of each cell of the row for $name in $table of $html.
 *
 * @return array<int, string>
 */
function summaryCells(string $html, string $table, string $name): array
{
    $row = Str::betweenFirst(Str::after($html, $table), '<td class="fw-semibold text-nowrap">'.$name.'</td>', '</tr>');
    preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

    return array_map(fn (string $cell) => trim(strip_tags($cell)), $cells[1]);
}

it('shows each person\'s day as the sheet does, worked out from the RFQs, with the total counting each RFQ once', function () {
    ['ops' => $ops] = summaryDay();
    SourcingDayFigure::factory()->create(['user_id' => User::query()->where('name', 'Riley Chen')->value('id'), 'day' => '2026-10-05', 'successful_calls' => 7, 'unanswered_calls' => 2, 'unattended_calls' => 1, 'expected_rfqs' => 4]);
    SourcingDayFigure::factory()->create(['user_id' => User::query()->where('name', 'Sam Rivera')->value('id'), 'day' => '2026-10-05', 'successful_calls' => 3, 'unanswered_calls' => null, 'unattended_calls' => null, 'expected_rfqs' => null]);

    $report = test()->actingAs(userWithRole('HR Executive'))->get(summaryUrl())->assertOk();
    $html = $report->getContent();

    foreach (['RFQ Finalized Successfully', 'Total items in Finalized RFQ', 'No of Pending RFQ', 'Total items in Pending RFQ', 'RFQ pending for Customer Clarifications', 'No of successful calls made to suppliers', 'No of unanswered calls made to suppliers', 'Unattended calls from supplier', 'Number of expected RFQ', 'Number of Received RFQ'] as $column) {
        expect($html)->toContain($column);
    }

    // Finalized 1 (10 items), pending 1 (8 items), waiting 1, then the calls.
    expect(summaryCells($html, 'data-sourcing-summary', 'Riley Chen'))->toBe(['1', '10', '1', '8', '1', '7', '2', '1'])
        ->and(summaryCells($html, 'data-sourcing-summary', 'Sam Rivera'))->toBe(['0', '0', '1', '8', '0', '3', '—', '—'])
        // Expected, received.
        ->and(summaryCells($html, 'data-sourcing-expected', 'Riley Chen'))->toBe(['4', '3'])
        ->and(summaryCells($html, 'data-sourcing-expected', 'Sam Rivera'))->toBe(['—', '1']);

    // RFQ8103 is pending once in the total, though both have a part of it.
    expect($report->viewData('report')['total'])->toMatchArray([
        'finalized' => 1, 'finalized_items' => 10, 'pending' => 1, 'pending_items' => 8, 'customer' => 1,
        'successful_calls' => 10, 'unanswered_calls' => 2, 'unattended_calls' => 1,
    ]);
});

it('is for those who see the performance reports, and on their sidebar and tabs', function () {
    foreach (['Sourcing', 'Data Entry', 'Business Development'] as $role) {
        test()->actingAs(userWithRole($role))->get(summaryUrl())->assertForbidden();
    }

    foreach (['Admin', 'Senior Operations', 'HR Executive'] as $role) {
        test()->actingAs(userWithRole($role))->get(summaryUrl())->assertOk()
            ->assertSee('href="'.summaryUrl().'"', false);
    }
});

it('lets Senior Operations set a day\'s figures right in the report, a blank clearing one', function () {
    ['riley' => $riley, 'sam' => $sam, 'ops' => $ops] = summaryDay();

    test()->actingAs($ops)->get(summaryUrl())->assertOk()
        ->assertSee('id="sourcing-figures-form"', false)
        ->assertSee('name="figures['.$riley->id.'][expected_rfqs]"', false)
        ->assertSee('name="figures['.$riley->id.'][successful_calls]"', false);

    test()->actingAs($ops)->from(summaryUrl())->put(route('admin.reports.sourcing-summary.update'), [
        'day' => '2026-10-05',
        'figures' => [
            $riley->id => ['expected_rfqs' => '6', 'successful_calls' => '9', 'unanswered_calls' => '', 'unattended_calls' => ''],
            $sam->id => ['expected_rfqs' => '', 'successful_calls' => '', 'unanswered_calls' => '', 'unattended_calls' => ''],
        ],
    ])->assertRedirect(summaryUrl())->assertSessionHas('status', 'Saved the figures for Oct 05, 2026.');

    $saved = SourcingDayFigure::query()->sole();

    expect($saved)
        ->user_id->toBe($riley->id)
        ->expected_rfqs->toBe(6)
        ->successful_calls->toBe(9)
        ->unanswered_calls->toBeNull();

    test()->actingAs($ops)->get(summaryUrl())->assertOk()
        ->assertSee('name="figures['.$riley->id.'][expected_rfqs]" value="6"', false);

    // Saved again, blank: cleared, the row kept to no harm.
    test()->actingAs($ops)->put(route('admin.reports.sourcing-summary.update'), [
        'day' => '2026-10-05',
        'figures' => [$riley->id => ['expected_rfqs' => '', 'successful_calls' => '9']],
    ]);
    expect($saved->refresh()->expected_rfqs)->toBeNull()->and($saved->successful_calls)->toBe(9);
});

it('refuses figures that make no sense, or for anyone outside Sourcing', function () {
    ['riley' => $riley, 'ops' => $ops] = summaryDay();
    $update = fn (array $data) => test()->actingAs($ops)->from(summaryUrl())->put(route('admin.reports.sourcing-summary.update'), $data);

    $update(['day' => '2026-10-05', 'figures' => [$riley->id => ['expected_rfqs' => '-1']]])->assertSessionHasErrors();
    $update(['day' => '2026-10-05', 'figures' => [$riley->id => ['expected_rfqs' => 'many']]])->assertSessionHasErrors();
    $update(['day' => '2026-10-06', 'figures' => [$riley->id => ['expected_rfqs' => '1']]])->assertSessionHasErrors(['day' => 'Pick today or an earlier day.']);
    $update(['day' => '2026-10-05', 'figures' => [$ops->id => ['expected_rfqs' => '1']]])->assertSessionHasNoErrors();

    expect(SourcingDayFigure::query()->count())->toBe(0);
});

it('lets only Senior Operations and Admin change the report\'s figures — HR Executive only sees them', function () {
    ['riley' => $riley] = summaryDay();
    $hr = userWithRole('HR Executive');
    $data = ['day' => '2026-10-05', 'figures' => [$riley->id => ['expected_rfqs' => '2']]];

    test()->actingAs($hr)->get(summaryUrl())->assertOk()
        ->assertDontSee('id="sourcing-figures-form"', false)
        ->assertDontSee('Save figures');

    foreach ([$hr, $riley, userWithRole('Data Entry')] as $person) {
        test()->actingAs($person)->put(route('admin.reports.sourcing-summary.update'), $data)->assertForbidden();
    }

    expect(SourcingDayFigure::query()->count())->toBe(0);

    test()->actingAs(userWithRole('Admin'))->put(route('admin.reports.sourcing-summary.update'), $data)->assertSessionHasNoErrors();

    expect(SourcingDayFigure::query()->sole()->expected_rfqs)->toBe(2);
});

it('adds the typed-in figures up over a range, where they can\'t be changed', function () {
    ['riley' => $riley, 'ops' => $ops] = summaryDay();
    SourcingDayFigure::factory()->create(['user_id' => $riley->id, 'day' => '2026-10-02', 'successful_calls' => 5, 'unanswered_calls' => 1, 'unattended_calls' => 0, 'expected_rfqs' => 3]);
    SourcingDayFigure::factory()->create(['user_id' => $riley->id, 'day' => '2026-10-05', 'successful_calls' => 7, 'unanswered_calls' => null, 'unattended_calls' => 0, 'expected_rfqs' => 4]);
    // Outside the range.
    SourcingDayFigure::factory()->create(['user_id' => $riley->id, 'day' => '2026-09-30', 'successful_calls' => 100, 'unanswered_calls' => 100, 'unattended_calls' => 100, 'expected_rfqs' => 100]);

    $html = test()->actingAs($ops)->get(summaryUrl(['from' => '2026-10-01', 'to' => '2026-10-05']))->assertOk()
        ->assertDontSee('id="sourcing-figures-form"', false)
        ->assertSee('Pick a single day to change them.')
        ->getContent();

    expect(array_slice(summaryCells($html, 'data-sourcing-summary', e($riley->name)), 5))->toBe(['12', '1', '0'])
        ->and(summaryCells($html, 'data-sourcing-expected', e($riley->name))[0])->toBe('7');
});

it('narrows to the people picked', function () {
    ['riley' => $riley, 'ops' => $ops] = summaryDay();

    test()->actingAs($ops)->get(summaryUrl(['people' => [$riley->id]]))->assertOk()
        ->assertSeeInOrder(['data-sourcing-summary', 'Riley Chen'], false)
        ->assertDontSee('<td class="fw-semibold text-nowrap">Sam Rivera</td>', false);
});

it('counts a part as received when it first reached them, not before', function () {
    ['riley' => $riley, 'ops' => $ops] = summaryDay();

    // Yesterday: nothing had reached anyone.
    $yesterday = test()->actingAs($ops)->get(summaryUrl(['from' => '2026-10-04', 'to' => '2026-10-04']))->assertOk()->viewData('report');

    expect($yesterday['rows']->firstWhere('user.id', $riley->id)['received'])->toBe(0);
});

it('lets a Sourcing member enter their own calls on their dashboard, today or an earlier day', function () {
    ['riley' => $riley, 'sam' => $sam, 'ops' => $ops] = summaryDay();

    test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('id="supplier-calls"', false)
        ->assertSee('Your supplier calls')
        ->assertSee('value="2026-10-05"', false)
        ->assertDontSee('expected_rfqs');

    test()->actingAs($riley)->from(route('admin.dashboard'))->patch(route('admin.sourcing-calls.update'), [
        'day' => '2026-10-05', 'successful_calls' => '8', 'unanswered_calls' => '2', 'unattended_calls' => '',
        // Not theirs to set, nor someone else's to enter.
        'expected_rfqs' => '99', 'user_id' => $sam->id,
    ])->assertRedirect(route('admin.dashboard'))->assertSessionHas('status', 'Saved your supplier calls for Oct 05, 2026.');

    $saved = SourcingDayFigure::query()->sole();

    expect($saved)
        ->user_id->toBe($riley->id)
        ->successful_calls->toBe(8)
        ->unanswered_calls->toBe(2)
        ->unattended_calls->toBeNull()
        ->expected_rfqs->toBeNull();

    // It comes back filled in, and in the report.
    test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('name="successful_calls" id="calls-successful_calls"', false)
        ->assertSee('value="8"', false);
    expect(array_slice(summaryCells(
        test()->actingAs(userWithRole('HR Executive'))->get(summaryUrl())->getContent(), 'data-sourcing-summary', 'Riley Chen'
    ), 5))->toBe(['8', '2', '—']);

    // An earlier day they forgot, reached by picking it.
    test()->actingAs($riley)->get(route('admin.dashboard', ['calls_date' => '2026-10-02']))->assertOk()
        ->assertSee('name="day" value="2026-10-02"', false);
    test()->actingAs($riley)->patch(route('admin.sourcing-calls.update'), ['day' => '2026-10-02', 'successful_calls' => '1'])->assertSessionHasNoErrors();
    expect(SourcingDayFigure::query()->where('day', '2026-10-02')->sole()->successful_calls)->toBe(1);

    // A later day isn't offered, nor taken.
    test()->actingAs($riley)->get(route('admin.dashboard', ['calls_date' => '2026-10-09']))->assertOk()
        ->assertSee('name="day" value="2026-10-05"', false);
    test()->actingAs($riley)->patch(route('admin.sourcing-calls.update'), ['day' => '2026-10-06', 'successful_calls' => '1'])
        ->assertSessionHasErrorsIn('sourcingCalls', ['day' => 'Pick today or an earlier day.']);
    test()->actingAs($riley)->patch(route('admin.sourcing-calls.update'), ['day' => '2026-10-05', 'successful_calls' => '-3'])
        ->assertSessionHasErrorsIn('sourcingCalls', ['successful_calls' => 'A count can\'t be less than 0.']);

    // Their saves leave what Senior Operations set; only Sourcing has calls of their own.
    $saved->update(['expected_rfqs' => 5]);
    test()->actingAs($riley)->patch(route('admin.sourcing-calls.update'), ['day' => '2026-10-05', 'successful_calls' => '9']);
    expect($saved->refresh())->expected_rfqs->toBe(5)->successful_calls->toBe(9);

    test()->actingAs($ops)->patch(route('admin.sourcing-calls.update'), ['day' => '2026-10-05', 'successful_calls' => '1'])->assertForbidden();
});

it('writes nothing for a member who saves blanks, and nothing when nothing changed', function () {
    ['riley' => $riley] = summaryDay();

    test()->actingAs($riley)->patch(route('admin.sourcing-calls.update'), ['day' => '2026-10-05', 'successful_calls' => '', 'unanswered_calls' => '', 'unattended_calls' => ''])->assertSessionHasNoErrors();

    expect(DB::table('sourcing_day_figures')->count())->toBe(0);

    SourcingDayFigure::record($riley->id, '2026-10-05', ['successful_calls' => 4]);
    $updatedAt = SourcingDayFigure::query()->sole()->updated_at;

    test()->travel(5)->minutes();
    SourcingDayFigure::record($riley->id, '2026-10-05', ['successful_calls' => 4]);

    expect(SourcingDayFigure::query()->sole()->updated_at->equalTo($updatedAt))->toBeTrue();
});
