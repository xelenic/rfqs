<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\RfqReturn;
use App\Models\Setting;
use App\Models\SourcingDayFigure;
use App\Models\User;
use App\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Travels to $at ("Y-m-d H:i") in Colombo — working hours 08:30–17:00 on
 * weekdays, lunch 12:30–13:30.
 */
function kpiClock(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, Setting::DEFAULT_TIMEZONE));
}

/**
 * Someone in Sourcing called $name, who joined on $joinedOn (or never said).
 */
function kpiMember(string $name, ?string $joinedOn = null): User
{
    $member = userWithRole('Sourcing');
    $member->forceFill(['name' => $name, 'joined_on' => $joinedOn])->save();

    return $member;
}

/**
 * The Sourcing KPI report's rows for $month, as Admin sees them, by name.
 *
 * @param  array<string, mixed>  $query
 * @return array{rows: Collection<string, array<string, mixed>>, total: array<string, mixed>}
 */
function kpiReport(string $month, array $query = []): array
{
    $report = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.sourcing-kpi', ['month' => $month] + $query))->assertOk()->viewData('report');

    return ['rows' => $report['rows']->keyBy(fn (array $row) => $row['user']->name), 'total' => $report['total']];
}

/**
 * Riley Chen's September, from the 1st (a Tuesday), with Sam Rivera beside
 * them doing nothing:
 *
 * - RFQ9001 (Critical, 10 items): sourced in 30 minutes, finalized, and the
 *   General Manager approves it — won.
 * - RFQ9002 (Critical, 5 items): sourced over a day and a half — past its two
 *   hours — then finalized.
 * - RFQ9003 (Low, 8 items): finalized, then Senior Operations rejects it back.
 * - RFQ9004 (Low, 2 items): finalized, approved by Senior Operations, then the
 *   Head of Business Development returns it for changes — not a rejection.
 * - Supplier calls: 10 successful and 2 unattended on the 1st, 5 and 1 on the 2nd.
 *
 * Ends on 28 November.
 *
 * @return array{riley: User, sam: User}
 */
function septemberKpiWork(): array
{
    $riley = kpiMember('Riley Chen');
    $sam = kpiMember('Sam Rivera');
    [$ops, $head, $assistant, $gm, $dataEntry] = [userWithRole('HBDs Operations'), userWithRole('Head of Business Development'), userWithRole('GMs Operations'), userWithRole('General Manager'), userWithRole('Data Entry')];

    kpiClock('2026-09-01 08:30');
    $won = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9001', 'priority_level' => 'Critical', 'number_of_items' => 10]), [1 => $riley]);
    $late = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9002', 'priority_level' => 'Critical', 'number_of_items' => 5]), [1 => $riley]);

    kpiClock('2026-09-01 09:00');
    $won->refresh()->completeSourcingPart(1);
    $won->refresh()->completeDataEntryPart(1, $dataEntry);
    kpiClock('2026-09-01 10:00');
    $won->refresh()->finalizePart(1);
    $won->refresh()->approveSeniorOpsPart(1, $ops);
    $won->refresh()->approveHeadOfBdPart(1, $head);
    $won->refresh()->recordGmAssistantPart(1, $assistant);
    kpiClock('2026-09-01 11:00');
    $won->refresh()->approveGmPart(1, $gm);

    kpiClock('2026-09-02 12:00');
    $late->refresh()->completeSourcingPart(1);
    $late->refresh()->completeDataEntryPart(1, $dataEntry);
    kpiClock('2026-09-02 14:00');
    $late->refresh()->finalizePart(1);

    kpiClock('2026-09-03 08:30');
    $rejected = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9003', 'priority_level' => 'Low', 'number_of_items' => 8]), [1 => $riley]);
    $returned = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9004', 'priority_level' => 'Low', 'number_of_items' => 2]), [1 => $riley]);
    kpiClock('2026-09-03 09:00');
    foreach ([$rejected, $returned] as $rfq) {
        $rfq->refresh()->completeSourcingPart(1);
        $rfq->refresh()->completeDataEntryPart(1, $dataEntry);
        $rfq->refresh()->finalizePart(1);
    }
    kpiClock('2026-09-03 11:00');
    $rejected->refresh()->rejectPartToStage(1, 'sourcing', 'Wrong supplier', $ops, 'senior_ops_review');
    $returned->refresh()->approveSeniorOpsPart(1, $ops);
    $returned->refresh()->rejectPartToStage(1, 'sourcing', 'Check the freight', $head, 'head_of_bd_review', asReturn: true);

    SourcingDayFigure::factory()->create(['user_id' => $riley->id, 'day' => '2026-09-01', 'successful_calls' => 10, 'unanswered_calls' => 4, 'unattended_calls' => 2]);
    SourcingDayFigure::factory()->create(['user_id' => $riley->id, 'day' => '2026-09-02', 'successful_calls' => 5, 'unanswered_calls' => null, 'unattended_calls' => 1]);
    // Another month's calls stay out of September.
    SourcingDayFigure::factory()->create(['user_id' => $riley->id, 'day' => '2026-10-01', 'successful_calls' => 99, 'unanswered_calls' => null, 'unattended_calls' => 99]);

    kpiClock('2026-11-28 12:00');

    return compact('riley', 'sam');
}

// ---- Cols A and B: working days and expected RFQs ---------------------------------

it('counts working days from the join date, skipping holidays, and the RFQs expected day by day through the tiers', function () {
    kpiClock('2026-11-28 12:00');
    Setting::putHolidays(['2026-09-15' => 'Poya day']);
    kpiMember('Joiner', '2026-09-08');
    kpiMember('Veteran', '2026-01-05');
    kpiMember('Crosser', '2026-06-16');
    kpiMember('Unknown');
    tap(kpiMember('Leaver', '2026-01-05'))->forceFill(['deactivated_at' => CarbonImmutable::parse('2026-09-21 09:00', Setting::DEFAULT_TIMEZONE)])->save();
    kpiMember('Not yet', '2026-10-10');

    $rows = kpiReport('2026-09')['rows'];
    $daysAndExpected = fn (string $name) => [$rows[$name]['days'], $rows[$name]['expected']];

    // Joined Tuesday the 8th: 16 working days, the 15th a holiday — 0, 0, then 1, 1, 1, then 2 a day.
    expect($daysAndExpected('Joiner'))->toBe([16, 25])
        // Long past the tiers: 21 working days at 3.
        ->and($daysAndExpected('Veteran'))->toBe([21, 63])
        // Their 56th–60th working days at 2, then 3 from their 61st.
        ->and($daysAndExpected('Crosser'))->toBe([21, 58])
        // No join date: the full rate every day, and said so.
        ->and($daysAndExpected('Unknown'))->toBe([21, 63])
        ->and($rows['Unknown']['joinedOn'])->toBeNull()
        // Deactivated on the 21st: the 1st to the 18th.
        ->and($daysAndExpected('Leaver'))->toBe([13, 39])
        // Not with the company yet, with nothing in the month: left out.
        ->and($rows->has('Not yet'))->toBeFalse();

    Setting::putKpiSettledRate(2);
    $rows = kpiReport('2026-09')['rows'];

    expect([$rows['Veteran']['expected'], $rows['Crosser']['expected'], $rows['Joiner']['expected']])->toBe([42, 42, 25]);
});

it('counts the month we\'re in up to today', function () {
    kpiClock('2026-11-28 12:00');
    kpiMember('Riley Chen', '2026-01-05');

    // November 2026 to the 28th: 20 weekdays.
    expect(kpiReport('2026-11')['rows']['Riley Chen']['days'])->toBe(20)
        ->and(test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.sourcing-kpi'))->assertOk()->viewData('month'))->toBe('2026-11');
});

// ---- The rest of the columns ------------------------------------------------------

it('works out what each person received, completed, had rejected, let go overdue, won and called, with the rates and the total', function () {
    septemberKpiWork();

    ['rows' => $rows, 'total' => $total] = kpiReport('2026-09');
    $riley = collect($rows['Riley Chen'])->except(['user', 'durations', 'average'])->all();

    expect($riley)->toBe([
        'joinedOn' => null,
        'days' => 22,
        'expected' => 66,
        'received' => 4,
        // RFQ9003 and RFQ9004 went back, so they're not finalized now.
        'completed' => 2,
        'rateOfExpected' => 3.03,
        'rateOfReceived' => 50.0,
        'items' => 25,
        // RFQ9003's Reject — RFQ9004's Return for changes doesn't count.
        'rejected' => 1,
        // RFQ9002, sourced over its two hours.
        'overdue' => 1,
        'successfulCalls' => 15,
        'callsPerDay' => 0.68,
        // RFQ9001, approved by the General Manager.
        'won' => 1,
        'winRate' => 50.0,
        'unattendedCalls' => 3,
    ])
        ->and($rows['Riley Chen']['average'])->toBeGreaterThan(0)
        ->and(collect($rows['Sam Rivera'])->only(['days', 'expected', 'completed', 'rateOfExpected', 'winRate'])->all())->toBe(['days' => 22, 'expected' => 66, 'completed' => 0, 'rateOfExpected' => 0.0, 'winRate' => 0.0])
        ->and(collect($total)->only(['days', 'expected', 'received', 'completed', 'rateOfExpected', 'won', 'callsPerDay'])->all())
        ->toBe(['days' => 44, 'expected' => 132, 'received' => 4, 'completed' => 2, 'rateOfExpected' => 1.52, 'callsPerDay' => 0.34, 'won' => 1]);

    // Nothing of it in October but the calls.
    $october = kpiReport('2026-10')['rows']['Riley Chen'];
    expect([$october['received'], $october['completed'], $october['rejected'], $october['overdue'], $october['won'], $october['unattendedCalls']])->toBe([0, 0, 0, 0, 0, 99]);
});

it('records whether a send-back was a Return, and tells those from before apart by their thread', function () {
    septemberKpiWork();

    expect(RfqReturn::query()->orderBy('id')->pluck('is_return')->all())->toBe([false, true]);

    $migration = require database_path('migrations/2026_11_28_133416_add_is_return_to_rfq_returns_table.php');
    $migration->down();
    $migration->up();

    expect(RfqReturn::query()->orderBy('id')->pluck('is_return')->all())->toBe([false, true])
        ->and(RfqComment::query()->where('action', 'rejected')->count())->toBe(2);
});

// ---- The page and its Excel download ----------------------------------------------

it('shows the sheet\'s codes and headings, a row a person and the total', function () {
    septemberKpiWork();

    test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.sourcing-kpi', ['month' => '2026-09']))->assertOk()
        ->assertSeeInOrder(['Number of working days for the month', 'Expected RFQ to be completed', 'Total RFQ received', 'Total quotations completed', 'RFQ Completed Rate (Based on b) %', 'Quotation Winning Rate %', 'Unattended calls from suppliers'])
        ->assertSeeInOrder(['Riley Chen', 'Join date not set', '22', '66', '4', '2', '3.03%', '50.00%'])
        ->assertSee(route('admin.reports.sourcing-kpi.export', ['month' => '2026-09']), false);
});

it('downloads the report as a workbook: codes, headings, a row a person, live formulas for the rates and sums for the total', function () {
    septemberKpiWork();

    $response = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.sourcing-kpi.export', ['month' => '2026-09']))->assertOk()
        ->assertDownload('sourcing-kpi-2026-09.xlsx');
    $path = $response->baseResponse->getFile()->getPathname();

    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $workbook = $zip->getFromName('xl/workbook.xml');
    $zip->close();

    $rows = Spreadsheet::rows($path, 'xlsx', 100);

    expect($rows[1])->toBe(['', 'a', 'b', 'c', 'd', 'e', 'f', '', '', '', '', 'g', 'h', 'i', 'k'])
        ->and(array_slice($rows[2], 0, 6))->toBe(['Employee', 'Number of working days for the month', 'Expected RFQ to be completed', 'Total RFQ received', 'Total quotations completed', 'RFQ Completed Rate (Based on b) %'])
        // Riley first, by name: their figures, the rates as Excel works them out.
        ->and(array_slice($rows[3], 0, 7))->toBe(['Riley Chen', '22', '66', '4', '2', '3.03', '50'])
        ->and($rows[5][0])->toBe('Total')
        ->and($sheet)
        ->toContain('<c r="F3"><f>IF(C3=0,0,ROUND(E3/C3*100,2))</f><v>3.03</v></c>')
        ->toContain('<f>IF(D3=0,0,ROUND(E3/D3*100,2))</f>')
        ->toContain('<f>IF(E3=0,0,ROUND(M3/E3*100,2))</f>')
        ->toContain('<f>SUM(B3:B4)</f>')
        ->toContain('<f>IF(C5=0,0,ROUND(E5/C5*100,2))</f>')
        // Completion time as hours and minutes.
        ->toContain('<c r="H3" s="2">')
        ->and($workbook)->toContain('<calcPr fullCalcOnLoad="1"/>');

    unlink($path);
});

it('is for those who see the performance reports, and is in their sidebar', function () {
    test()->actingAs(userWithRole('Sourcing'))->get(route('admin.reports.sourcing-kpi'))->assertForbidden();
    test()->actingAs(userWithRole('Sourcing'))->get(route('admin.reports.sourcing-kpi.export'))->assertForbidden();

    test()->actingAs(userWithRole('HR Executive'))->get(route('admin.reports.sourcing-kpi'))->assertOk();
    test()->actingAs(userWithRole('HBDs Operations'))->get(route('admin.dashboard'))->assertSee(route('admin.reports.sourcing-kpi'));
    test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard'))->assertSee(route('admin.reports.sourcing-kpi'));
});

// ---- Settings → Monthly KPI, and the join date ------------------------------------

it('lets settings.manage set the rate after 60 working days and the company holidays', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'kpi']))->assertOk()
        ->assertSee('id="settings-tab-kpi"', false)
        ->assertSee('Company holidays');

    test()->actingAs($admin)->patch(route('admin.settings.kpi'), ['settled_rate' => 2])->assertSessionHasNoErrors();
    test()->actingAs($admin)->patch(route('admin.settings.kpi'), ['settled_rate' => 99])->assertSessionHasErrorsIn('kpi', 'settled_rate');
    test()->actingAs($admin)->post(route('admin.settings.holidays.store'), ['day' => '2026-12-25', 'name' => 'Christmas Day'])->assertSessionHasNoErrors();
    test()->actingAs($admin)->post(route('admin.settings.holidays.store'), ['day' => '2026-09-15'])->assertSessionHasNoErrors();

    expect(Setting::kpiSettledRate())->toBe(2)
        ->and(Setting::holidays())->toBe(['2026-09-15' => '', '2026-12-25' => 'Christmas Day']);

    test()->actingAs($admin)->delete(route('admin.settings.holidays.destroy', '2026-09-15'))->assertSessionHasNoErrors();
    test()->actingAs(userWithRole('HR Executive'))->patch(route('admin.settings.kpi'), ['settled_rate' => 1])->assertForbidden();
    test()->actingAs(userWithRole('HR Executive'))->post(route('admin.settings.holidays.store'), ['day' => '2026-12-31'])->assertForbidden();

    expect(Setting::holidays())->toBe(['2026-12-25' => 'Christmas Day'])
        ->and(Setting::kpiSettledRate())->toBe(2);
});

it('keeps the day someone joined, given on the Users form', function () {
    foreach (['users.view', 'users.create', 'users.edit'] as $permission) {
        Permission::findOrCreate($permission);
    }
    Role::findOrCreate('Admin')->givePermissionTo(['users.view', 'users.create', 'users.edit']);
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->post(route('admin.users.store'), ['name' => 'Morgan Perera', 'email' => 'morgan@company.lk', 'joined_on' => '2026-09-08', 'roles' => ['Sourcing']])
        ->assertSessionHasNoErrors();
    $morgan = User::query()->where('email', 'morgan@company.lk')->sole();

    expect($morgan->joined_on->toDateString())->toBe('2026-09-08');

    test()->actingAs($admin)->put(route('admin.users.update', $morgan), ['name' => 'Morgan Perera', 'email' => 'morgan@company.lk', 'joined_on' => '2026-09-01', 'roles' => ['Sourcing']])
        ->assertSessionHasNoErrors();
    test()->actingAs($admin)->get(route('admin.users.show', $morgan->refresh()))->assertOk()->assertSee('Sep 01, 2026');

    // A form without it leaves it be.
    test()->actingAs($admin)->put(route('admin.users.update', $morgan), ['name' => 'Morgan P.', 'email' => 'morgan@company.lk', 'roles' => ['Sourcing']]);

    expect($morgan->refresh()->joined_on->toDateString())->toBe('2026-09-01')
        ->and(DB::table('users')->where('id', $morgan->id)->value('name'))->toBe('Morgan P.');
});
