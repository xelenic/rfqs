<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\SourcingDayFigure;
use App\Models\User;
use App\PerformanceReports;
use App\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The performance reports, for Admin, Senior Operations and HR Executive
 * (reports.performance), as the business specified them: Sourcing's
 * daily report (finalized, pending and waiting-for-customer RFQs, with their
 * items), Data Entry's daily report (RFQs completed, their items, the time
 * spent), Sourcing's monthly evaluation (RFQs received, quotations
 * completed, the average time to complete one, items) and the RFQ tracking
 * sheet, and the Sourcing Summary (one row a person, as the business's sheet
 * has it), and — since 2026-11-28 — Sourcing's monthly KPI report, which
 * downloads as an Excel workbook with live formulas. The person reports take
 * one person, several or everyone, and a day range or a month; each has its
 * totals at the bottom and sorts by name, date, RFQ count or item count. The
 * figures come from App\PerformanceReports.
 */
class PerformanceReportController extends Controller
{
    /**
     * How many RFQs the tracking sheet lists a page.
     */
    private const PER_PAGE = 50;

    /**
     * How the tracking sheet can be ordered.
     *
     * @var array<string, string>
     */
    public const TRACKING_SORTS = ['date' => 'Date', 'rfq' => 'RFQ number', 'wc' => 'WC number', 'priority' => 'Priority'];

    /**
     * Whether Sourcing has finalized the RFQs the tracking sheet lists
     * (?finalized=) — each part, as the sheet's column shows it.
     *
     * @var array<string, string>
     */
    public const TRACKING_FINALIZED = ['all' => 'Finalized or not', 'not_finalized' => 'Not finalized yet', 'finalized' => 'Finalized'];

    /**
     * The Sourcing KPI report's columns, after the person's name, as the
     * business's sheet has them: each one's letter code (row 1 of the
     * workbook; none for some) and heading (row 2). Their figures are
     * App\PerformanceReports::sourcingKpi()'s, by key.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KPI_COLUMNS = [
        'days' => ['a', 'Number of working days for the month'],
        'expected' => ['b', 'Expected RFQ to be completed'],
        'received' => ['c', 'Total RFQ received'],
        'completed' => ['d', 'Total quotations completed'],
        'rateOfExpected' => ['e', 'RFQ Completed Rate (Based on b) %'],
        'rateOfReceived' => ['f', 'RFQ Completed Rate (Based on c) %'],
        'average' => ['', 'Average RFQ completion Time'],
        'items' => ['', 'Total No of Items for Month'],
        'rejected' => ['', 'Number of rejected RFQs'],
        'overdue' => ['', 'Number of overdue RFQs'],
        'callsPerDay' => ['g', 'Average of successful calls made per day'],
        'won' => ['h', 'Total winning quotations'],
        'winRate' => ['i', 'Quotation Winning Rate %'],
        'unattendedCalls' => ['k', 'Unattended calls from suppliers'],
    ];

    /**
     * Sourcing's daily (and weekly) report — the Procurement Daily Report.
     */
    public function sourcingDaily(Request $request): View
    {
        $this->authorizeReports($request);

        $everyone = PerformanceReports::people('Sourcing');
        [$from, $to, $clamped] = $this->dayRange($request);
        $people = $this->chosen($request, $everyone);
        [$sort, $descending] = $this->sorting($request, PerformanceReports::SORTS, 'name');
        $report = (new PerformanceReports)->sourcingDaily($people, $from, $to);

        return view('admin.reports.performance.sourcing_daily', [
            'report' => ['rows' => PerformanceReports::sort($report['rows'], $sort, $descending, ['rfqs' => 'finalized', 'items' => 'finalized_items'])] + $report,
            'everyone' => $everyone,
            'chosen' => $people->pluck('id')->all(),
            'from' => $from,
            'to' => $to,
            'clamped' => $clamped,
            'sort' => $sort,
            'descending' => $descending,
        ]);
    }

    /**
     * The Sourcing Summary, day by day: a line for each day anyone's figures
     * were typed in (SourcingDayFigure), latest first, each opening that day's
     * sheet (sourcingSummary()).
     */
    public function sourcingSummaryDays(Request $request): View
    {
        $this->authorizeReports($request);

        $days = SourcingDayFigure::query()
            ->select('day')
            ->selectRaw('count(*) as people, sum(successful_calls) as successful_calls, sum(unanswered_calls) as unanswered_calls, sum(unattended_calls) as unattended_calls')
            ->groupBy('day')
            ->orderByDesc('day')
            ->paginate(SourcingDayFigureController::DAYS_PER_PAGE)
            ->withQueryString();

        return view('admin.reports.performance.sourcing_summary_days', [
            'days' => $days,
            'received' => (new PerformanceReports)->receivedOn($days->pluck('day')->all()),
            'expected' => (new PerformanceReports)->expectedOn($days->pluck('day')->all()),
            'canEditFigures' => $request->user()->canEditSourcingFigures(),
        ]);
    }

    /**
     * The Sourcing Summary's sheet: one row a person over a day or a range, as the
     * business's sheet has it — what the workflow knows, and the supplier
     * calls and expected RFQs typed in by hand (SourcingDayFigure). On one
     * day, Senior Operations and Admin can change those right there
     * (SourcingDayFigureController::updateForDay()).
     */
    public function sourcingSummary(Request $request): View
    {
        $this->authorizeReports($request);

        $everyone = PerformanceReports::people('Sourcing');
        [$from, $to, $clamped] = $this->dayRange($request);
        $people = $this->chosen($request, $everyone);

        return view('admin.reports.performance.sourcing_summary', [
            // A deactivated account with nothing in the range shows only when picked.
            'report' => (new PerformanceReports)->sourcingSummary($people, $from, $to, keepDeactivated: $people->count() < $everyone->count()),
            'everyone' => $everyone,
            'chosen' => $people->pluck('id')->all(),
            'from' => $from,
            'to' => $to,
            'clamped' => $clamped,
            'canEditFigures' => $from === $to && $request->user()->canEditSourcingFigures(),
        ]);
    }

    /**
     * Data Entry's daily report.
     */
    public function dataEntryDaily(Request $request): View
    {
        $this->authorizeReports($request);

        $everyone = PerformanceReports::people('Data Entry');
        [$from, $to, $clamped] = $this->dayRange($request);
        $people = $this->chosen($request, $everyone);
        [$sort, $descending] = $this->sorting($request, PerformanceReports::SORTS, 'name');
        $report = (new PerformanceReports)->dataEntryDaily($people, $from, $to);

        return view('admin.reports.performance.data_entry_daily', [
            'report' => ['rows' => PerformanceReports::sort($report['rows'], $sort, $descending, ['rfqs' => 'completed', 'items' => 'items'])] + $report,
            'everyone' => $everyone,
            'chosen' => $people->pluck('id')->all(),
            'from' => $from,
            'to' => $to,
            'clamped' => $clamped,
            'sort' => $sort,
            'descending' => $descending,
        ]);
    }

    /**
     * Sourcing's monthly performance evaluation.
     */
    public function sourcingMonthly(Request $request): View
    {
        $this->authorizeReports($request);

        $everyone = PerformanceReports::people('Sourcing');
        [$month, $thisMonth] = $this->month($request);
        $people = $this->chosen($request, $everyone);
        [$sort, $descending] = $this->sorting($request, ['name' => 'Name', 'rfqs' => 'RFQ count', 'items' => 'Item count'], 'name');
        $report = (new PerformanceReports)->sourcingMonthly($people, $month);

        return view('admin.reports.performance.sourcing_monthly', [
            'report' => ['rows' => PerformanceReports::sort($report['rows'], $sort, $descending, ['rfqs' => 'received', 'items' => 'items'])] + $report,
            'everyone' => $everyone,
            'chosen' => $people->pluck('id')->all(),
            'month' => $month,
            'monthLabel' => CarbonImmutable::parse("{$month}-01")->format('F Y'),
            'thisMonth' => $thisMonth,
            'sort' => $sort,
            'descending' => $descending,
        ]);
    }

    /**
     * Sourcing's monthly KPI report (App\PerformanceReports::sourcingKpi()):
     * one row a person for the month picked — this month's so far — with the
     * total, as the business's sheet has it (KPI_COLUMNS).
     */
    public function sourcingKpi(Request $request): View
    {
        $this->authorizeReports($request);

        $everyone = PerformanceReports::people('Sourcing');
        [$month, $thisMonth] = $this->month($request);
        $people = $this->chosen($request, $everyone);

        return view('admin.reports.performance.sourcing_kpi', [
            // A deactivated account with nothing in the month shows only when picked.
            'report' => (new PerformanceReports)->sourcingKpi($people, $month, keepDeactivated: $people->count() < $everyone->count()),
            'everyone' => $everyone,
            'chosen' => $people->pluck('id')->all(),
            'month' => $month,
            'monthLabel' => CarbonImmutable::parse("{$month}-01")->format('F Y'),
            'thisMonth' => $thisMonth,
        ]);
    }

    /**
     * The Sourcing KPI report as an Excel workbook: the letter codes in row
     * 1, the headings in row 2, a row a person, and the total — the
     * completed and winning rates (Cols E, F and M) as live formulas,
     * guarded against dividing by 0, and the total's counts as sums.
     */
    public function sourcingKpiExport(Request $request): BinaryFileResponse
    {
        $this->authorizeReports($request);

        $everyone = PerformanceReports::people('Sourcing');
        [$month] = $this->month($request);
        $people = $this->chosen($request, $everyone);
        $report = (new PerformanceReports)->sourcingKpi($people, $month, keepDeactivated: $people->count() < $everyone->count());

        return response()->download(
            Spreadsheet::xlsx($this->kpiSheet($report), "Sourcing KPI {$month}", [28, ...array_fill(0, count(self::KPI_COLUMNS), 16)], boldRows: [0, 1, $report['rows']->count() + 2]),
            "sourcing-kpi-{$month}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }

    /**
     * The Sourcing KPI workbook's rows (sourcingKpiExport()): codes, headings,
     * a row a person from row 3, and the total.
     *
     * @param  array{rows: Collection<int, array<string, mixed>>, total: array<string, mixed>}  $report
     * @return array<int, array<int, mixed>>
     */
    private function kpiSheet(array $report): array
    {
        $letters = collect(array_keys(self::KPI_COLUMNS))->mapWithKeys(fn (string $key, int $index) => [$key => chr(ord('B') + $index)]);
        $first = 3;
        $last = $first + $report['rows']->count() - 1;

        // A rate as Excel works it out: $of as a percentage of $whole, 0 when there's nothing to divide by.
        $rate = fn (string $of, string $whole, int $row, float $value) => [
            'formula' => "IF({$letters[$whole]}{$row}=0,0,ROUND({$letters[$of]}{$row}/{$letters[$whole]}{$row}*100,2))",
            'value' => $value,
        ];

        $cells = function (array $figures, int $row, ?string $name) use ($letters, $rate, $first, $last): array {
            $sum = fn (string $key) => $name === null && $last >= $first
                ? ['formula' => "SUM({$letters[$key]}{$first}:{$letters[$key]}{$last})", 'value' => $figures[$key]]
                : $figures[$key];

            return [
                $name ?? 'Total',
                $sum('days'),
                $sum('expected'),
                $sum('received'),
                $sum('completed'),
                $rate('completed', 'expected', $row, $figures['rateOfExpected']),
                $rate('completed', 'received', $row, $figures['rateOfReceived']),
                ['seconds' => $figures['average']],
                $sum('items'),
                $sum('rejected'),
                $sum('overdue'),
                $figures['callsPerDay'],
                $sum('won'),
                $rate('won', 'completed', $row, $figures['winRate']),
                $sum('unattendedCalls'),
            ];
        };

        return [
            ['', ...array_column(self::KPI_COLUMNS, 0)],
            ['Employee', ...array_column(self::KPI_COLUMNS, 1)],
            ...$report['rows']->values()->map(fn (array $row, int $index) => $cells($row, $first + $index, $row['user']->name))->all(),
            $cells($report['total'], $last + 1, null),
        ];
    }

    /**
     * The RFQ tracking sheet: every RFQ, by when it came in, with who in
     * Sourcing finalized it and whether it's open or closed — filtered by
     * date, RFQ number, WC number, priority, Sourcing member, status, and
     * whether Sourcing has finalized it (TRACKING_FINALIZED): not yet — a
     * part not cancelled still to finalize, or none assigned — or every part
     * still going finalized. With a Sourcing member, it's their parts that
     * are asked about.
     */
    public function rfqTracking(Request $request): View
    {
        $this->authorizeReports($request);

        $zone = Setting::timezone();
        $isDate = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        $filters = [
            'from' => $isDate($request->query('from')) ? $request->query('from') : null,
            'to' => $isDate($request->query('to')) ? $request->query('to') : null,
            'rfq' => $request->string('rfq')->trim()->toString(),
            'wc' => $request->string('wc')->trim()->toString(),
            'priority' => array_values(array_intersect((array) $request->query('priority', []), Rfq::PRIORITIES)),
            'person' => $request->integer('person') ?: null,
            'status' => in_array($request->query('status'), ['open', 'closed'], true) ? $request->query('status') : 'all',
            'finalized' => array_key_exists((string) $request->query('finalized'), self::TRACKING_FINALIZED) ? (string) $request->query('finalized') : 'all',
        ];

        // Finalized by Sourcing or not, as All RFQs asks it too — the chosen member's parts, if one is.
        $notFinalized = fn (Builder $query) => $query->notFinalizedBySourcing($filters['person']);
        $finalized = fn (Builder $query) => $query->finalizedBySourcing($filters['person']);
        [$sort, $descending] = $this->sorting($request, self::TRACKING_SORTS, 'date', true);

        $query = Rfq::query()
            ->visibleTo($request->user())
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('rfqs.created_at', '>=', CarbonImmutable::parse($from, $zone)->startOfDay()->setTimezone(config('app.timezone'))))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where('rfqs.created_at', '<=', CarbonImmutable::parse($to, $zone)->endOfDay()->setTimezone(config('app.timezone'))))
            ->when($filters['rfq'] !== '', fn (Builder $query) => $query->where('rfqs.rfq_number', 'like', "%{$filters['rfq']}%"))
            ->when($filters['wc'] !== '', fn (Builder $query) => $query->where('rfqs.wc_number', 'like', "%{$filters['wc']}%"))
            ->when($filters['priority'] !== [], fn (Builder $query) => $query->whereIn('rfqs.priority_level', $filters['priority']))
            ->when($filters['person'], fn (Builder $query, int $person) => $query->whereHas('assignees', fn (Builder $parts) => $parts
                ->where(fn (Builder $held) => $held->where('rfq_user.user_id', $person)->orWhere('rfq_user.finalized_by', $person))))
            ->when($filters['status'] === 'closed', fn (Builder $query) => $query->where('rfqs.status', 'Completed'))
            ->when($filters['status'] === 'open', fn (Builder $query) => $query->where('rfqs.status', '!=', 'Completed'))
            ->when($filters['finalized'] === 'not_finalized', $notFinalized)
            ->when($filters['finalized'] === 'finalized', $finalized);

        $summary = [
            'rfqs' => (clone $query)->count(),
            'closed' => (clone $query)->where('rfqs.status', 'Completed')->count(),
            'not_finalized' => (clone $query)->tap($notFinalized)->count(),
            'items' => (int) (clone $query)->sum('rfqs.number_of_items'),
        ];

        $direction = $descending ? 'desc' : 'asc';
        match ($sort) {
            'rfq' => $query->orderBy('rfqs.rfq_number', $direction),
            'wc' => $query->orderBy('rfqs.wc_number', $direction),
            'priority' => $query->orderByRaw(Rfq::priorityOrderSql('rfqs.priority_level').' '.$direction),
            default => $query->orderBy('rfqs.created_at', $direction),
        };

        $rfqs = $query->orderBy('rfqs.id', $direction)->with('assignees')->paginate(self::PER_PAGE)->withQueryString();
        $finalizers = User::query()
            ->whereIn('id', $rfqs->getCollection()->flatMap(fn (Rfq $rfq) => $rfq->assignees
                ->filter(fn ($assignee) => $assignee->pivot->finalized_at !== null)
                ->map(fn ($assignee) => $assignee->pivot->finalized_by ?? $assignee->id))->unique())
            ->pluck('name', 'id');

        return view('admin.reports.performance.rfq_tracking', [
            'rfqs' => $rfqs,
            'finalizers' => $finalizers,
            'waitingOnCustomer' => (new PerformanceReports)->waitingOnCustomer($rfqs->getCollection()->pluck('id')->all()),
            'summary' => $summary,
            'filters' => $filters,
            'sourcing' => PerformanceReports::people('Sourcing'),
            'sort' => $sort,
            'descending' => $descending,
        ]);
    }

    /**
     * Only Admin, Senior Operations and HR Executive see these reports
     * (User::canViewPerformanceReports()).
     */
    private function authorizeReports(Request $request): void
    {
        abort_unless($request->user()->canViewPerformanceReports(), 403, 'Your role can\'t see the performance reports.');
    }

    /**
     * The days a daily report covers: from and to ("Y-m-d") as asked, today
     * by default, to no later than today — and, if more than
     * PerformanceReports::MAX_DAYS were asked for, the last that many, with
     * whether it was cut short.
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    private function dayRange(Request $request): array
    {
        $today = Setting::today();
        $isDate = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        $to = $isDate($request->query('to')) ? min($request->query('to'), $today) : $today;
        $from = $isDate($request->query('from')) ? min($request->query('from'), $to) : $to;
        $earliest = CarbonImmutable::parse($to)->subDays(PerformanceReports::MAX_DAYS - 1)->toDateString();

        return $from < $earliest ? [$earliest, $to, true] : [$from, $to, false];
    }

    /**
     * The month a monthly report covers ("Y-m"), as asked — this month by
     * default, and never a later one — with this month.
     *
     * @return array{0: string, 1: string}
     */
    private function month(Request $request): array
    {
        $thisMonth = CarbonImmutable::now(Setting::timezone())->format('Y-m');
        $asked = (string) $request->query('month');

        return [preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $asked) && $asked <= $thisMonth ? $asked : $thisMonth, $thisMonth];
    }

    /**
     * Of $everyone, the people picked (people[]) — everyone when nobody is.
     *
     * @param  Collection<int, User>  $everyone
     * @return Collection<int, User>
     */
    private function chosen(Request $request, Collection $everyone): Collection
    {
        $ids = collect((array) $request->query('people', []))->map(fn (mixed $id) => (int) $id)->filter()->all();
        $picked = $everyone->whereIn('id', $ids)->values();

        return $picked->isEmpty() ? $everyone : $picked;
    }

    /**
     * The order asked for (sort, one of $sorts' keys; dir asc or desc), or
     * $default — descending by default if $descendingByDefault.
     *
     * @param  array<string, string>  $sorts
     * @return array{0: string, 1: bool}
     */
    private function sorting(Request $request, array $sorts, string $default, bool $descendingByDefault = false): array
    {
        $sort = array_key_exists((string) $request->query('sort'), $sorts) ? $request->query('sort') : $default;
        $direction = $request->query('dir');

        return [$sort, in_array($direction, ['asc', 'desc'], true) ? $direction === 'desc' : $descendingByDefault];
    }
}
