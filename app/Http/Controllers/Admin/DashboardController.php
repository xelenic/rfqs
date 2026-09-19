<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    /**
     * The selectable RFQ-activity time ranges, in days.
     *
     * @var array<int, int>
     */
    public const RANGES = [1, 7, 30, 90];

    /**
     * Display the admin dashboard.
     */
    public function index(Request $request): View
    {
        $canViewRfqs = $request->user()->can('rfqs.view');

        return view('admin.dashboard', [
            'usersCount' => User::count(),
            'rolesCount' => Role::count(),
            'permissionsCount' => Permission::count(),
            'rfqActivity' => $canViewRfqs ? $this->rfqActivity($request->integer('range', 7)) : null,
            'recentRfqs' => $canViewRfqs
                ? Rfq::query()->with('assignees')->latest()->limit(6)->get()
                : null,
            'notifications' => $this->notifications($request),
        ]);
    }

    /**
     * Personalized alerts for the dashboard's messages panel — only what's
     * actionable for this user, scoped to what they're allowed to see.
     *
     * @return array<int, array{type: string, icon: string, title: string, description: string, url: string}>
     */
    protected function notifications(Request $request): array
    {
        $user = $request->user();
        $notifications = [];

        if ($user->can('rfqs.view')) {
            $urgentPending = Rfq::where('status', 'Pending')->where('priority_level', 'Urgent')->count();
            if ($urgentPending > 0) {
                $notifications[] = [
                    'type' => 'danger',
                    'icon' => 'bi-exclamation-triangle-fill',
                    'title' => trans_choice('1 urgent RFQ needs attention|:count urgent RFQs need attention', $urgentPending, ['count' => $urgentPending]),
                    'description' => 'Marked Urgent and still pending.',
                    'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                ];
            }

            $awaitingOperations = Rfq::where('status', 'Pending')->whereNull('operations_assigned_by')->count();
            if ($awaitingOperations > 0) {
                $notifications[] = [
                    'type' => 'warning',
                    'icon' => 'bi-diagram-2',
                    'title' => trans_choice('1 RFQ awaiting Operations|:count RFQs awaiting Operations', $awaitingOperations, ['count' => $awaitingOperations]),
                    'description' => "No one's routed these yet.",
                    'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                ];
            }

            $awaitingSourcing = Rfq::where('status', 'Pending')->needingSourcing()->count();
            if ($awaitingSourcing > 0) {
                $notifications[] = [
                    'type' => 'warning',
                    'icon' => 'bi-person-plus',
                    'title' => trans_choice('1 RFQ awaiting Sourcing|:count RFQs awaiting Sourcing', $awaitingSourcing, ['count' => $awaitingSourcing]),
                    'description' => 'Not yet assigned to anyone.',
                    'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                ];
            }
        }

        $assignedToMe = $user->assignedRfqs()->where('status', 'Pending')->count();
        if ($assignedToMe > 0) {
            $notifications[] = [
                'type' => 'primary',
                'icon' => 'bi-person-check',
                'title' => trans_choice('1 RFQ assigned to you|:count RFQs assigned to you', $assignedToMe, ['count' => $assignedToMe]),
                'description' => 'Still pending your action.',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
            ];
        }

        return $notifications;
    }

    /**
     * Bucket RFQs created in the selected range by status, zero-filled so the
     * chart never skips a period with no activity.
     *
     * @return array{
     *     range: int, labels: array<int, string>, pending: array<int, int>,
     *     completed: array<int, int>, pendingTotal: int, completedTotal: int
     * }
     */
    protected function rfqActivity(int $range): array
    {
        $range = in_array($range, self::RANGES, true) ? $range : 7;
        $hourly = $range === 1;

        $start = $hourly
            ? Carbon::now()->subHours(23)->startOfHour()
            : Carbon::now()->subDays($range - 1)->startOfDay();

        $bucketKey = fn (Carbon $date) => $hourly ? $date->format('Y-m-d H') : $date->format('Y-m-d');

        $buckets = collect(range(0, $hourly ? 23 : $range - 1))
            ->map(fn ($i) => $hourly ? $start->copy()->addHours($i) : $start->copy()->addDays($i));

        $pending = $buckets->mapWithKeys(fn ($date) => [$bucketKey($date) => 0])->all();
        $completed = $pending;

        Rfq::query()
            ->where('created_at', '>=', $start)
            ->get(['status', 'created_at'])
            ->each(function (Rfq $rfq) use (&$pending, &$completed, $bucketKey) {
                $key = $bucketKey($rfq->created_at);

                if (! array_key_exists($key, $pending)) {
                    return;
                }

                if ($rfq->status === 'Completed') {
                    $completed[$key]++;
                } else {
                    $pending[$key]++;
                }
            });

        return [
            'range' => $range,
            'labels' => $buckets->map(fn (Carbon $date) => $hourly ? $date->format('g A') : $date->format('M j'))->all(),
            'pending' => array_values($pending),
            'completed' => array_values($completed),
            'pendingTotal' => array_sum($pending),
            'completedTotal' => array_sum($completed),
        ];
    }
}
