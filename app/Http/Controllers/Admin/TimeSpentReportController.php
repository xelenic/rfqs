<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\RfqStep;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The Time Spent report, for Admin, Senior Operations and HR Executive
 * (User::TIME_SPENT_REPORT_ROLES): the working time Sourcing, Data Entry and
 * GM Assistant have spent on each RFQ (Rfq::timeSpent()), and on average
 * across them, so a slow stage stands out; how each stands against its
 * targets (Rfq::deadlines()) — every timed step, judged as the dashboards
 * judge it, so an RFQ they call Overdue is on Deadline exceeded too, with
 * those that finished a step late; and work done out of hours
 * (RfqStep::isOutOfHoursWork()) flagged — each role's figure with how it
 * came about on hover (Rfq::timeBreakdown()). One tab per lens — TABS — and
 * a search across them. Only RFQs some tracked role has started on are listed.
 */
class TimeSpentReportController extends Controller
{
    /**
     * The report's tabs, in order, with what each lists. The first is the
     * one it opens on.
     *
     * @var array<string, string>
     */
    public const TABS = [
        'pending' => 'Pending',
        'exceeded' => 'Deadline exceeded',
        'out-of-hours' => 'Out of working hours',
        'closed' => 'Closed',
        'all' => 'All',
    ];

    private const PER_PAGE = 20;

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()->canViewTimeSpentReport(), 403, 'Only Admin, Senior Operations and HR Executive can see the time spent report.');

        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? $request->query('tab') : array_key_first(self::TABS);
        $search = $request->string('search')->trim()->toString();

        $now = now();

        // Every RFQ the search leaves, with its time and deadline — the tabs
        // need them worked out, so the list is paged after rather than by the
        // query.
        $all = Rfq::query()
            ->whereHas('steps')
            ->when($search, fn ($query) => $query->where(fn ($q) => $q
                ->where('wc_number', 'like', "%{$search}%")
                ->orWhere('rfq_number', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")))
            ->with(['steps', 'assignees'])
            ->latest()
            ->get()
            ->map(fn (Rfq $rfq) => [
                'rfq' => $rfq,
                'time' => $rfq->timeSpent($now),
                'deadline' => $rfq->deadlines($now),
            ]);

        $tabs = collect(self::TABS)->map(fn (string $label, string $key) => [
            'label' => $label,
            'rows' => $all->filter(fn (array $row) => $this->isOnTab($key, $row))->values(),
        ]);
        $listed = $tabs[$tab]['rows'];

        $page = LengthAwarePaginator::resolveCurrentPage();
        $pageRows = $listed->forPage($page, self::PER_PAGE);
        $rfqs = new LengthAwarePaginator($pageRows->pluck('rfq'), $listed->count(), self::PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        // How each figure on the page came about, stretch by stretch — the
        // breakdown shown on hovering it — with everyone's name looked up once.
        $names = User::query()
            ->whereIn('id', $pageRows->flatMap(fn (array $row) => $row['rfq']->steps->map(fn (RfqStep $step) => $step->workerId()))->filter()->unique())
            ->pluck('name', 'id');

        return view('admin.reports.time_spent', [
            'rfqs' => $rfqs,
            'times' => $pageRows->mapWithKeys(fn (array $row) => [$row['rfq']->id => $row['time']]),
            'breakdowns' => $pageRows->mapWithKeys(fn (array $row) => [$row['rfq']->id => $row['rfq']->timeBreakdown($now, null, $names)]),
            'deadlines' => $pageRows->mapWithKeys(fn (array $row) => [$row['rfq']->id => $row['deadline']]),
            'averages' => $this->averages($listed->pluck('time')),
            'outOfHours' => [
                'seconds' => (int) $all->sum(fn (array $row) => $row['time']['out_of_hours']),
                'stretches' => (int) $all->sum(fn (array $row) => $row['time']['out_of_hours_count']),
                'rfqs' => $tabs['out-of-hours']['rows']->count(),
            ],
            'tabs' => $tabs->map(fn (array $tabRows) => ['label' => $tabRows['label'], 'count' => $tabRows['rows']->count()])->all(),
            // Of those past a deadline: overdue now, as the dashboards count
            // them, and those with a step finished late.
            'exceeded' => [
                'overdue' => $tabs['exceeded']['rows']->filter(fn (array $row) => $row['deadline']['overdue'])->count(),
                'late' => $tabs['exceeded']['rows']->filter(fn (array $row) => $row['deadline']['late'] !== [])->count(),
            ],
            'tab' => $tab,
            'roles' => array_keys(RfqStep::ROLE_STEPS),
            'search' => $search,
        ]);
    }

    /**
     * Whether an RFQ's row belongs on a tab.
     *
     * @param  array{rfq: Rfq, time: array{out_of_hours_count: int}, deadline: array{overdue: bool, exceeded: bool}|null}  $row
     */
    private function isOnTab(string $tab, array $row): bool
    {
        return match ($tab) {
            'pending' => $row['rfq']->status === 'Pending',
            'exceeded' => $row['deadline']['exceeded'] ?? false,
            'out-of-hours' => $row['time']['out_of_hours_count'] > 0,
            'closed' => $row['rfq']->status === 'Completed',
            default => true,
        };
    }

    /**
     * Per role, across the RFQs listed: the average working and elapsed time
     * spent on an RFQ — over the ones the role has worked on, not every RFQ —
     * how many those are, and their working time in all. In seconds.
     *
     * @param  Collection<int, array{roles: array<string, array{seconds: int, elapsed: int, rounds: int}>}>  $times
     * @return array<string, array{average: int, elapsed: int, rfqs: int, seconds: int}>
     */
    private function averages(Collection $times): array
    {
        return collect(array_keys(RfqStep::ROLE_STEPS))->mapWithKeys(function (string $role) use ($times) {
            $worked = $times->filter(fn (array $time) => $time['roles'][$role]['rounds'] > 0);
            $seconds = (int) $worked->sum(fn (array $time) => $time['roles'][$role]['seconds']);
            $elapsed = (int) $worked->sum(fn (array $time) => $time['roles'][$role]['elapsed']);

            return [$role => [
                'average' => $worked->isEmpty() ? 0 : intdiv($seconds, $worked->count()),
                'elapsed' => $worked->isEmpty() ? 0 : intdiv($elapsed, $worked->count()),
                'rfqs' => $worked->count(),
                'seconds' => $seconds,
            ]];
        })->all();
    }
}
