<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use App\PerformanceReports;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The performance reports, for Admin, Senior Operations and HR Executive
 * (User::PERFORMANCE_REPORT_ROLES), as the business specified them: Sourcing's
 * daily report (finalized, pending and waiting-for-customer RFQs, with their
 * items), Data Entry's daily report (RFQs completed, their items, the time
 * spent), Sourcing's monthly evaluation (RFQs received, quotations
 * completed, the average time to complete one, items) and the RFQ tracking
 * sheet. The person reports take one person, several or everyone, and a day
 * range or a month; each has its totals at the bottom and sorts by name,
 * date, RFQ count or item count. The figures come from App\PerformanceReports.
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
        $thisMonth = CarbonImmutable::now(Setting::timezone())->format('Y-m');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) && $request->query('month') <= $thisMonth
            ? $request->query('month')
            : $thisMonth;
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

        // A part still to finalize: not finalized, not cancelled — the chosen member's, if one is.
        $partToFinalize = fn ($parts) => $parts->whereNull('rfq_user.finalized_at')
            ->where(fn ($live) => $live->whereNull('rfq_user.status')->orWhere('rfq_user.status', '!=', Rfq::CANCELLED))
            ->when($filters['person'], fn ($mine, int $person) => $mine->where('rfq_user.user_id', $person));
        $notFinalized = fn (Builder $query) => $query->where(fn (Builder $rfqs) => $rfqs
            ->whereHas('assignees', $partToFinalize)
            ->when(! $filters['person'], fn (Builder $unassigned) => $unassigned->orWhereDoesntHave('assignees')));
        $finalized = fn (Builder $query) => $query
            ->whereHas('assignees', fn ($parts) => $parts->whereNotNull('rfq_user.finalized_at')
                ->when($filters['person'], fn ($theirs, int $person) => $theirs->where(fn ($by) => $by->where('rfq_user.finalized_by', $person)->orWhere('rfq_user.user_id', $person))))
            ->whereDoesntHave('assignees', $partToFinalize);
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
        abort_unless($request->user()->canViewPerformanceReports(), 403, 'Only Admin, Senior Operations and HR Executive can see the performance reports.');
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
